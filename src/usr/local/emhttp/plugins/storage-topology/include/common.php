<?PHP
/* Shared by both pages: acknowledged problems (stored on the flash drive) and the problems list with its
 * Acknowledge / Unacknowledge controls, plus the diagnostics download link.
 *
 * A problem's id is a hash of its level and key (what it is about, e.g. "shelf|<wwn>|fan|1"), so it survives
 * collections; its fingerprint is a hash of its value (e.g. the fan's status, flags and whether it turns), so an
 * acknowledgement lapses and the problem shows again when the value changes. Acknowledged problems do not count
 * toward a page's overall status.
 */

// Tests point this at a temporary file (define ST_ACK_FILE first, or set the environment variable).
if (!defined('ST_ACK_FILE')) define('ST_ACK_FILE', getenv('ST_ACK_FILE') ?: '/boot/config/plugins/storage-topology/acks.json');
const ST_ACK_SPACES = ['storage', 'network'];
const ST_ACK_MAX = 500;               // per page; the oldest acknowledgements are dropped beyond this

function st_problem_id(string $level, string $key): string { return substr(sha1("$level|$key"), 0, 16); }
function st_problem_fp(string $value): string { return substr(sha1($value), 0, 12); }

function st_acks_read(string $file = ST_ACK_FILE): array {
  $j = json_decode((string)@file_get_contents($file), true);
  return is_array($j) ? $j : [];
}

// Split $m['problems'] into active ones and $m['acked'], and set $m['level'] from the active ones.
function st_acks_apply(array &$m, string $space, string $file = ST_ACK_FILE): void {
  $acks = st_acks_read($file)[$space] ?? [];
  $active = []; $acked = [];
  foreach ($m['problems'] as $p) {
    $p['id'] = st_problem_id($p['level'], (string)($p['key'] ?? $p['text']));
    $p['fp'] = st_problem_fp((string)($p['value'] ?? $p['text']));
    $a = $acks[$p['id']] ?? null;
    $p['stale'] = $a !== null && ($a['fp'] ?? '') !== $p['fp'];       // acknowledged before, but it has changed since
    if ($a !== null && !$p['stale']) { $p['acked_at'] = (int)($a['at'] ?? 0); $acked[] = $p; } else $active[] = $p;
  }
  $m['problems'] = $active;
  $m['acked'] = $acked;
  $m['level'] = 'ok';
  foreach (['crit', 'warn', 'info'] as $l) if (in_array($l, array_column($active, 'level'), true)) { $m['level'] = $l; break; }
}

// Add ($entry = [fp, text]) or remove ($entry = null) one acknowledgement. Locked, written to a temp file and renamed.
function st_acks_write(string $space, string $id, ?array $entry, string $file = ST_ACK_FILE): bool {
  if (!in_array($space, ST_ACK_SPACES, true) || !preg_match('/^[0-9a-f]{16}$/', $id)) return false;
  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
  $lock = @fopen('/var/run/storage-topology-acks.lock', 'c') ?: @fopen("$file.lock", 'c');
  if (!$lock || !flock($lock, LOCK_EX)) return false;
  $all = st_acks_read($file);
  $acks = $all[$space] ?? [];
  if ($entry === null) unset($acks[$id]);
  else {
    $acks[$id] = ['fp' => (string)$entry['fp'], 'text' => mb_substr((string)($entry['text'] ?? ''), 0, 300), 'at' => time()];
    if (count($acks) > ST_ACK_MAX) { uasort($acks, fn($a, $b) => ($b['at'] ?? 0) <=> ($a['at'] ?? 0)); $acks = array_slice($acks, 0, ST_ACK_MAX, true); }
  }
  $all[$space] = $acks;
  $tmp = "$file.tmp." . getmypid();
  $ok = @file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) . "\n") !== false && @rename($tmp, $file);
  if (!$ok) @unlink($tmp);
  flock($lock, LOCK_UN);
  fclose($lock);
  return $ok;
}

// The problems list with its controls. $m has gone through st_acks_apply.
function st_problems_html(array $m, string $space): string {
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
  $ctl = fn($p, $act, $label) => "<a href='#' class='st-ack' data-space='{$h($space)}' data-id='{$h($p['id'])}' data-fp='{$h($p['fp'])}'"
    . " data-text='{$h($p['text'])}' data-act='$act'>$label</a>";
  $out = '';
  if ($m['problems']) {
    $out .= "<ul class='st-problems'>";
    foreach ($m['problems'] as $p) $out .= "<li class='st-{$h($p['level'])}'>{$h($p['text'])}"
      . ($p['stale'] ? " <span class='st-dim'>(changed since it was acknowledged)</span>" : '') . $ctl($p, 'ack', 'Acknowledge') . '</li>';
    $out .= '</ul>';
  }
  if ($m['acked'] ?? []) {
    $out .= "<details class='st-acked'><summary class='st-dim'>Acknowledged (" . count($m['acked']) . ')</summary><ul class="st-problems">';
    foreach ($m['acked'] as $p) $out .= "<li class='st-dim'>{$h($p['text'])}" . ($p['acked_at'] ? ' <span>(' . $h(date('Y-m-d H:i', $p['acked_at'])) . ')</span>' : '')
      . $ctl($p, 'unack', 'Unacknowledge') . '</li>';
    $out .= '</ul></details>';
  }
  return $out;
}

// Script for the Acknowledge links: POST to include/ack.php with Unraid's csrf_token (checked by the webGUI's
// local_prepend.php before ack.php runs), then reload.
function st_problems_js(string $csrf): string {
  $tok = json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
  return <<<JS
<script>
document.addEventListener('click', function (e) {
  var a = e.target.closest ? e.target.closest('a.st-ack') : null;
  if (!a) return;
  e.preventDefault();
  var f = new FormData();
  f.append('csrf_token', typeof csrf_token !== 'undefined' ? csrf_token : $tok);
  ['space', 'id', 'fp', 'text', 'act'].forEach(function (k) { f.append(k, a.dataset[k] || ''); });
  a.style.pointerEvents = 'none';
  fetch('/plugins/storage-topology/include/ack.php', {method: 'POST', body: f, credentials: 'same-origin'})
    .then(function (r) { return r.json().catch(function () { return {error: 'HTTP ' + r.status}; }); })
    .then(function (j) { if (j && j.ok) location.reload(); else { a.style.pointerEvents = ''; alert('Could not save: ' + ((j && j.error) || 'unknown error')); } })
    .catch(function (err) { a.style.pointerEvents = ''; alert('Could not save: ' + err); });
});
</script>
JS;
}

function st_diag_html(): string {
  return "<a id='st-diag' href='/plugins/storage-topology/include/diagnostics.php?anon=1' style='margin-left:10px' "
    . "title='A .tar.gz of what both pages collected, with versions, to attach to a bug report'>Download diagnostics</a> "
    . "<label class='st-dim' style='margin-left:4px;cursor:pointer'><input type='checkbox' id='st-anon' checked "
    . "onchange=\"document.getElementById('st-diag').href='/plugins/storage-topology/include/diagnostics.php?anon='+(this.checked?1:0)\"> "
    . "anonymise (serials, WWNs, SAS addresses, MACs, hostnames)</label>";
}

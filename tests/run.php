<?php
/* Model tests on synthetic fixtures (tests/fixtures, written by tests/make_fixtures.py): php tests/run.php
 * Loads each fixture through topo_load(), checks the model and the problems list, exercises acknowledgements
 * against a temporary store (never the flash drive), anonymises a fixture and loads it again, and renders the
 * storage page from each fixture with E_ALL. Exits 1 on any failure.
 */
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
  if (!(error_reporting() & $no)) return true;
  throw new ErrorException($str, 0, $no, $file, $line);
});

$repo = dirname(__DIR__);
$plug = "$repo/src/usr/local/emhttp/plugins/storage-topology";
$fx = __DIR__ . '/fixtures';
$tmp = sys_get_temp_dir() . '/st-tests.' . getmypid();
mkdir($tmp, 0700, true);
define('ST_ACK_FILE', "$tmp/acks.json");
putenv('ST_ACK_FILE=' . ST_ACK_FILE);
require_once "$plug/include/topology.php";
require_once "$plug/include/network.php";
require_once "$plug/include/common.php";
require_once "$plug/include/anonymise.php";

$failed = 0; $passed = 0; $section = '';
function t(bool $cond, string $what): void {
  global $failed, $passed, $section;
  if ($cond) { $passed++; return; }
  $failed++;
  fwrite(STDERR, "FAIL [$section] $what\n");
}
function texts(array $m): array { return array_column($m['problems'], 'text'); }
function matching(array $m, string $re, ?string $level = null): array {
  return array_values(array_filter($m['problems'], fn($p) => preg_match($re, $p['text']) && ($level === null || $p['level'] === $level)));
}
function copy_dir(string $from, string $to): void {
  @mkdir($to, 0700, true);
  foreach (scandir($from) as $f) if ($f !== '.' && $f !== '..') {
    is_dir("$from/$f") ? copy_dir("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
  }
}
function rm_dir(string $d): void {
  foreach (scandir($d) ?: [] as $f) if ($f !== '.' && $f !== '..') is_dir("$d/$f") ? rm_dir("$d/$f") : unlink("$d/$f");
  @rmdir($d);
}
function load(string $dir): array { $m = topo_load($dir); st_acks_apply($m, 'storage'); return $m; }
function dump_problems(array $m): string { return implode("\n  ", array_map(fn($p) => "{$p['level']}: {$p['text']}", $m['problems'])); }

// ---------------------------------------------------------------------------------------------------------
$section = 'it-mode-sas3224';
$dir = "$fx/it-mode-sas3224";
$m = load($dir);
t(count($m['controllers']) === 1 && ($m['controllers'][0]['sysfs'] ?? false), 'one kernel-view controller');
$c = $m['controllers'][0];
t($c['model'] === 'SAS9305-24i', 'controller model from board_name');
t(count($c['ports']) === 7, '7 ports (6 x1 to SSDs, 1 x4 to the expander), got ' . count($c['ports']));
$exp = array_values(array_filter($c['ports'], fn($p) => $p['expander']));
t(count($exp) === 1 && count($exp[0]['phys']) === 4, 'one x4 expander port');
t(($exp[0]['remote_max'] ?? 0) == 6.0 && ($exp[0]['expect'] ?? 0) == 6.0 && !$exp[0]['slow'], 'expander port judged against the SAS2 expander (6G), not slow');
t(str_starts_with($exp[0]['attached_label'] ?? '', 'Shelf sg18 expander'), 'expander port labelled with its shelf: ' . ($exp[0]['attached_label'] ?? ''));
t(($exp[0]['connectors'] ?? []) === ['C1'], 'expander port on connector C1');
$p0 = $c['ports'][0];
t(str_starts_with($p0['attached_label'], 'cache (/dev/sda, SAMSUNG'), 'port 0 labelled with its disk: ' . $p0['attached_label']);
t(str_contains($p0['type'], 'SATA') && !$p0['slow'] && $p0['expect'] == 6.0, 'SATA end device judged against 6G');
t(!matching($m, '/below|lanes/'), 'no link-rate warnings; problems:  ' . dump_problems($m));
t(!matching($m, '/^Collection/'), 'no collection problems');
t(!matching($m, '/connector C\d/'), 'breakout connector C2 (2 of 4 lanes to drives) is not a partial link');
t(count($m['direct']) === 7, '7 drives outside any bay, got ' . count($m['direct']));
$names = array_map(fn($x) => $m['drives'][$x['drive']]['unraid'], $m['direct']);
sort($names);
t($names === ['cache', 'cache2', 'cache3', 'cache4', 'cache5', 'cache6', 'cache7'], 'direct-attached SSDs carry their Unraid names: ' . implode(',', $names));
$sdg = array_values(array_filter($m['direct'], fn($x) => $m['drives'][$x['drive']]['dev'] === 'sdg'))[0] ?? null;
t($sdg && str_contains($sdg['where'], 'expander') && str_contains($sdg['where'], 'PHY 20'), 'SSD on the expander without a bay: ' . ($sdg['where'] ?? '-'));
t($sdg && $m['drives'][$sdg['drive']]['rate'] == 6.0 && $m['drives'][$sdg['drive']]['max_rate'] == 6.0, 'its link is 6G of 6G');
t(count($m['shelves']) === 1, 'one shelf');
$s = reset($m['shelves']);
t($s['serial'] === '' && $s['ioms'] === [], 'shelf without serial and I/O modules');
t(count($s['drives']) === 12 && $s['drives'][0]['unraid'] === 'parity', '12 bays with disks, bay 0 = parity');
$fans = matching($m, '/Fan \d/');
t(count($fans) === 3 && count(matching($m, '/Fan [123]: fail at 0 rpm/', 'warn')) === 3, 'fans 1-3 flagged (fail at 0 rpm), 4-5 (not installed / unknown) not: ' . implode(' | ', array_column($fans, 'text')));
t($m['level'] === 'warn', 'overall warn before acknowledging');
// Acknowledge the three fans: the page turns healthy.
foreach ($fans as $p) t(st_acks_write('storage', $p['id'], ['fp' => $p['fp'], 'text' => $p['text']]), 'ack written');
t(is_file(ST_ACK_FILE) && !is_file('/boot/config/plugins/storage-topology/acks.json.tmp.' . getmypid()), 'acks go to the test store');
$m = load($dir);
t(count($m['acked']) === 3 && !matching($m, '/Fan/') && $m['level'] === 'ok', 'acknowledged fans leave the list and the status: ' . $m['level']);
// Fan 1 starts turning: its acknowledgement lapses (same id, new fingerprint).
$d2 = "$tmp/it-fan";
copy_dir($dir, $d2);
$j = json_decode(file_get_contents("$d2/ses_sg18.json"), true);
foreach ($j['join_of_diagnostic_pages']['element_list'] as &$e) if ($e['element_type']['i'] === 3 && $e['element_number'] === 0) $e['status_descriptor']['calculated_fan_speed'] = 1200;
unset($e);
file_put_contents("$d2/ses_sg18.json", json_encode($j));
$m2 = load($d2);
$f1 = matching($m2, '/Fan 1: fail$/');
t(count($f1) === 1 && $f1[0]['stale'] && count($m2['acked']) === 2, 'fan 1 comes back (changed since acknowledged), fans 2-3 stay acknowledged');
// Unacknowledge fan 2.
$f2 = array_values(array_filter($m['acked'], fn($p) => str_contains($p['text'], 'Fan 2')))[0];
st_acks_write('storage', $f2['id'], null);
$m = load($dir);
t(count($m['acked']) === 2 && count(matching($m, '/Fan 2/')) === 1, 'unacknowledged fan 2 is back');
unlink(ST_ACK_FILE);

// ---------------------------------------------------------------------------------------------------------
$section = 'usb-short-ses';
$dir = "$fx/usb-short-ses";
$m = load($dir);
t($m['no_sas'], 'no SAS controllers');
t(count($m['enclosures']) === 2, 'two USB enclosures');
$e6 = $m['enclosures']['sg6'] ?? null; $e8 = $m['enclosures']['sg8'] ?? null;
t($e6 && $e6['usb'] && $e6['short_status_only'] && $e6['level'] === 'ok' && $e6['status_text'] === 'OK', 'sg6: USB, short status only, OK');
t($e6 && $e6['name'] === 'WD My Book Duo 25F6' && ($e6['disks'][0]['dev'] ?? '') === 'sdf' && $e6['disks'][0]['unraid'] === 'disk3', 'sg6 linked to sdf = disk3');
t($e8 && ($e8['disks'][0]['dev'] ?? '') === 'sdg' && $e8['disks'][0]['unraid'] === '', 'sg8 linked to sdg (unassigned)');
t(!$m['problems'] && $m['level'] === 'ok', 'no problems: ' . dump_problems($m));
t(!$m['shelves'] && !$m['other_ses'] && !$m['direct'], 'no shelves, no other SES cards, no direct list');
$d2 = "$tmp/usb-warn";
copy_dir($dir, $d2);
file_put_contents("$d2/ses_sg6.short", "status=0x4\n");
$m = load($d2);
t(($m['enclosures']['sg6']['level'] ?? '') === 'warn' && count(matching($m, '/USB enclosure WD My Book Duo 25F6 \(sg6\): status NON-CRIT/', 'warn')) === 1, 'status 0x4 = NON-CRIT: warn');
$d3 = "$tmp/usb-noshort";
copy_dir($dir, $d3);
unlink("$d3/ses_sg6.short"); unlink("$d3/ses_sg8.short");
$m = load($d3);
t(count($m['enclosures']) === 2 && !$m['problems'], 'short status read from the sg_ses error text when the .short file is missing');
t(topo_short_status(0x0)['level'] === 'ok' && topo_short_status(0x8)['level'] === 'info' && topo_short_status(0x2)['level'] === 'crit'
  && topo_short_status(0x1)['level'] === 'crit' && topo_short_status(0x10)['level'] === 'info', 'short status bits');

// ---------------------------------------------------------------------------------------------------------
$section = 'hba-connectors';
$m = load("$fx/hba-wide-8");
$c = $m['controllers'][0] ?? null;
t($c && count($c['ports']) === 1 && $c['ports'][0]['wide'] && count($c['ports'][0]['phys']) === 8, '(a) one wide x8 port');
t($c && $c['ports'][0]['connectors'] === ['C0', 'C1'], '(a) over C0+C1');
t($c && count($c['connectors']) === 4 && count($c['connectors'][0]['linked']) === 4 && count($c['connectors'][1]['linked']) === 4, '(a) C0 and C1 4 of 4');
t(!array_filter($m['problems'], fn($p) => $p['level'] !== 'info') , '(a) no warnings: ' . dump_problems($m));
$m = load("$fx/hba-wide-4-c1-unused");
t(!array_filter($m['problems'], fn($p) => $p['level'] !== 'info'), '(b) unused C1 is not a problem: ' . dump_problems($m));
t(($m['controllers'][0]['connectors'][1]['level'] ?? '') === 'absent', '(b) C1 absent');
$m = load("$fx/hba-partial-c1");
t(count(matching($m, '/connector C1 \(PHYs 4-7\): only 2 of 4 lanes linked/', 'warn')) === 1, '(c) C1 partially linked: ' . dump_problems($m));
t(!matching($m, '/connector C0/'), '(c) C0 fine');
$m = load("$fx/hba-dual-expander");
t(count(matching($m, '/C0 and C1 go to two different expanders of the same enclosure/', 'info')) === 1, '(d) dual-expander note: ' . dump_problems($m));
t(!array_filter($m['problems'], fn($p) => $p['level'] !== 'info'), '(d) no warnings');

// ---------------------------------------------------------------------------------------------------------
$section = 'storcli-direct';
$m = load("$fx/storcli-direct");
$where = array_column($m['direct'], 'where');
sort($where);
t($where === ['c0 e252 slot 3', 'c0 slot 5'], 'drives in virtual enclosure 252 and without enclosure are direct: ' . implode(', ', $where));
t(array_map(fn($x) => $m['drives'][$x['drive']]['unraid'], $m['direct']) == ['disk1', 'disk2'] || array_map(fn($x) => $m['drives'][$x['drive']]['unraid'], $m['direct']) == ['disk2', 'disk1'], 'with their Unraid names');
t(!matching($m, '/below|Collection/'), 'SATA links at 6G are fine, no collection errors: ' . dump_problems($m));
t(count($m['controllers'][0]['connectors'] ?? []) === 2, 'two derived connectors');

// ---------------------------------------------------------------------------------------------------------
$section = 'emc-ktn-stl3';
$m = load("$fx/emc-ktn-stl3");
$s = reset($m['shelves']);
t($s && array_column($s['ioms'], 'name') === ['LCC A', 'LCC B'] && array_column($s['ioms'], 'fw') === ['0B70', '0B70'], 'LCC A/B as I/O modules with firmware 0B70');
t($s && array_column($s['groups']['psu'], 'name') === ['Power Supply A', 'Power Supply B'] && array_column($s['groups']['psu'], 'fw') === ['2150', '2150'], 'PSU names and revisions from page 1');
t($s && in_array('Temp. Sensor M', array_column($s['groups']['temp'], 'name'), true) && in_array('Cooling Fan A 2', array_column($s['groups']['fan'], 'name'), true), 'sensor names with per-subenclosure index');
$crit = array_values(array_filter($m['problems'], fn($p) => $p['level'] === 'crit'));
t(count($crit) === 1 && str_contains($crit[0]['text'], 'PSU B: no AC input'), 'one critical problem, PSU B without AC: ' . dump_problems($m));
t(!matching($m, '/enclosure status/'), 'the enclosure element is not a separate problem');
t(!matching($m, '/I\/O modules answer SES/'), 'LCCs sharing one SES device is not a missing I/O module');
t($s && $s['fault_led'] && $m['shelves'][$s['key']]['fault_explained'], 'shelf header gets the fault LED note');
$m0 = topo_load("$fx/emc-ktn-stl3");
t(!array_filter($m0['shelves'][$s['key']]['ioms'], fn($i) => $i['fw'] === ''), 'no blank I/O module firmware');
t($s && !array_filter(array_merge(...array_values($s['groups'])), fn($x) => $x['desc'] !== ''), 'sg_ses\'s "<null>" descriptor (no page 7) reads as empty');

// ---------------------------------------------------------------------------------------------------------
$section = 'ses-join-fallback';
// What the page reads from an element list: type, number, overall/individual, descriptor, status, bay number and
// the bay's SAS addresses. (Fan speeds, temperatures, voltages and currents are live readings: the pages and the join
// of the real fixture were read milliseconds apart, so those may differ.)
function ses_view(array $els): array {
  return array_map(function ($e) {
    $sd = $e['status_descriptor'] ?? []; $ae = $e['additional_element_status_descriptor'] ?? [];
    $phys = array_values(array_filter(array_map(fn($p) => topo_addr($p['sas_address'] ?? ''), $ae['phy_descriptor_list'] ?? []), fn($a) => trim($a, '0') !== ''));
    return [$e['element_type']['i'], $e['element_number'], $e['individual'], $e['descriptor'], $sd['status']['meaning'] ?? '',
            $ae['device_slot_number'] ?? $sd['slot_address'] ?? null, $phys];
  }, $els);
}
$dir = "$fx/netapp-ds424-pages";
$real = topo_json("$dir/ses_sg4.json")['join_of_diagnostic_pages']['element_list'];
$pages = fn($aes = null, $ed = true) => topo_ses_join(topo_json("$dir/sescfg_sg4.json"), topo_json("$dir/sesstat_sg4.json"),
                                                       $ed ? topo_json("$dir/sesdesc_sg4.json") : null, $aes ?? topo_json("$dir/sesaes_sg4.json"));
$built = $pages();
t(is_array($built) && count($built) === count($real) && count($real) > 100, 'DS424: one row per element (' . count($real) . ')');
t(ses_view($built) === ses_view($real), 'DS424: joining pages 1, 2, 7 and 0Ah gives what sg_ses --json --join gives');
$occupied = fn($els) => count(array_filter(ses_view($els), fn($v) => $v[0] === 1 && $v[2] && $v[6]));
t($occupied($real) >= 20 && $occupied($built) === $occupied($real), 'DS424: every occupied bay has its SAS address (' . $occupied($real) . ')');
// Additional element status descriptors are the join's, except empty bays (page 0Ah flags them invalid, without detail).
$aesOf = fn($els) => array_map(fn($e) => $e['additional_element_status_descriptor'], array_filter($els, fn($e) => !empty($e['additional_element_status_descriptor'])
  && ($e['additional_element_status_descriptor']['phy_descriptor_list'][0]['phy_index'] ?? 0) !== 255));
t($aesOf($built) === $aesOf($real), 'DS424: additional element status descriptors equal the join\'s');
// The other ways of matching page 0Ah to elements give the same join: EIP=0 (in order) and EIIOE=1 (index counts overall elements).
$aes = topo_json("$dir/sesaes_sg4.json");
$noEip = $aes; $eiioe1 = $aes;
$indIdx = array_keys(array_filter($built, fn($e) => $e['individual']));
$L = 'additional_element_status_diagnostic_page'; $G = 'additional_element_status_by_element_type_list'; $D = 'additional_element_status_descriptor_list';
foreach ($aes[$L][$G] as $g => $grp) foreach ($grp[$D] as $i => $d) {
  $x = &$noEip[$L][$G][$g][$D][$i]['additional_element_status_descriptor'];
  $x['eip'] = 0; unset($x['element_index']); unset($x);
  $y = &$eiioe1[$L][$G][$g][$D][$i]['additional_element_status_descriptor'];
  $y['element_index'] = $indIdx[$y['element_index']]; unset($y);
}
t($pages($noEip) === $built, 'page 0Ah without element indexes (EIP=0) is matched in order');
t($pages($eiioe1) === $built, 'page 0Ah whose element indexes count overall elements (EIIOE=1) is recognised');
$no7 = $pages(null, false);
t(is_array($no7) && ($no7[1]['descriptor'] ?? '') === '<null>' && $occupied($no7) === $occupied($real), 'page 7 is optional ("<null>" descriptors, as sg_ses writes them)');
t(topo_ses_join(null, topo_json("$dir/sesstat_sg4.json"), null, null) === null && topo_ses_join(topo_json("$dir/sescfg_sg4.json"), null, null, null) === null, 'pages 1 and 2 are required');
// The page model from the separate pages (--join crashed) equals the one from --join, live readings aside.
$d2 = "$tmp/ds424-pages";
copy_dir($dir, $d2);
file_put_contents("$d2/ses_sg4.json", '');
file_put_contents("$d2/timings", str_replace('ses_sg4.json|0|', 'ses_sg4.json|139|', file_get_contents("$d2/timings")));
$mj = load($dir); $mp = load($d2);
// (Empty bays: the join decodes their invalid page 0Ah descriptor to a zero address, the pages leave it out.)
$shelf = fn($m) => array_map(fn($s) => ['label' => $s['label'], 'serial' => $s['serial'], 'status' => $s['status'], 'ioms' => $s['ioms'],
  'groups' => array_map(fn($g) => array_map(fn($x) => ['phys' => array_values(array_filter($x['phys'] ?? [], fn($a) => trim($a, '0') !== ''))]
    + array_diff_key($x, array_flip(['value', 'rpm', 'code'])), $g), $s['groups'])], $m['shelves']);
t(count($mj['shelves']) === 1 && $shelf($mp) === $shelf($mj), 'DS424: shelf, I/O modules, PSUs, sensors, connectors and bays are the same from the pages');
t(texts($mp) === texts($mj) && !matching($mp, '/^Collection/'), 'DS424: same problems, and the failed --join is not a collection problem: ' . dump_problems($mp));
$s = reset($mp['shelves']);
t($s && count($s['ioms']) === 2 && $s['ioms'][0]['fw'] !== '' && count($s['groups']['psu']) === 4 && count($s['groups']['slot']) === 24, 'DS424 from pages: 2 IOMs with firmware, 4 PSUs, 24 bays');

// EMC KTN-STL3 where sg_ses --json --join crashes: pages 1, 2 and 0Ah, no page 7.
$mj = load("$fx/emc-ktn-stl3"); $mp = load("$fx/emc-ktn-stl3-pages");
$named = fn($m) => array_map(fn($s) => ['ioms' => array_column($s['ioms'], 'name'), 'fw' => array_column($s['ioms'], 'fw'),
  'names' => array_map(fn($g) => array_map(fn($x) => [$x['name'], $x['status'], $x['level'], $x['slot'] ?? null, $x['phys'] ?? null], $g), $s['groups'])], $m['shelves']);
t(count($mp['shelves']) === 1 && $named($mp) === $named($mj), 'EMC from pages: same named elements as from --join');
$crit = array_values(array_filter($mp['problems'], fn($p) => $p['level'] === 'crit'));
t(count($crit) === 1 && str_contains($crit[0]['text'], 'PSU B: no AC input') && texts($mp) === texts($mj), 'EMC from pages: PSU B without AC, same problems as --join: ' . dump_problems($mp));
t(!matching($mp, '/^Collection/'), 'EMC from pages: --join exit 139, no page 7 and DID_SOFT_ERROR on stderr are not collection problems');
$s = reset($mp['shelves']);
t($s && count(array_filter($s['groups']['slot'], fn($x) => $x['phys'])) === 15, 'EMC from pages: 15 bays with SAS addresses from page 0Ah');
// Without the status page there is nothing to show: that is reported.
$d3 = "$tmp/emc-nostat";
copy_dir("$fx/emc-ktn-stl3-pages", $d3);
unlink("$d3/sesstat_sg3.json");
file_put_contents("$d3/timings", str_replace('sesstat_sg3.json|0|', 'sesstat_sg3.json|5|', file_get_contents("$d3/timings")));
$m3 = load($d3);
t(!$m3['shelves'] && matching($m3, '/Collection: ses_sg3\.json: exit 139/') && matching($m3, '/Collection: sesstat_sg3\.json: exit 5/') && matching($m3, '/sg3: no SES data/'), 'both failing is reported: ' . dump_problems($m3));

// ---------------------------------------------------------------------------------------------------------
$section = 'anonymise';
$d2 = "$tmp/anon";
copy_dir("$fx/it-mode-sas3224", $d2);
$st = st_anon_new(['testhost']);   // random offset, as in a real archive
$files = array_values(array_filter(scandir($d2), fn($f) => is_file("$d2/$f")));
$texts = [];
foreach ($files as $f) {
  if ($f === 'disks.ini' || $f === 'devs.ini') { file_put_contents("$d2/$f", st_anon_ini(file_get_contents("$d2/$f"), $f === 'devs.ini')); continue; }
  $texts[$f] = file_get_contents("$d2/$f");
  st_anon_scan($st, $f, $texts[$f]);
}
foreach ($texts as $f => $tx) file_put_contents("$d2/$f", st_anon_text($st, $f, $tx));
$all = implode("\n", array_map(fn($f) => file_get_contents("$d2/$f"), $files));
t(!str_contains($all, 'S2TVNX0TEST') && !str_contains($all, '7JTEST') && str_contains($all, 'SN0001'), 'serials replaced');
t(!preg_match('/500605b0000a1b3f|500605b012345600|5000cca2500000/i', $all), 'SAS addresses and WWNs replaced');
t(!str_contains($all, (string)0x5000cca250000001) && !str_contains($all, (string)0x500605b0000a1b3f), 'decimal SAS addresses (sg_ses JSON) replaced');
// Serials spelled out in the SES configuration page's vendor-specific hex (EMC KTN-STL3, NetApp) become same-length tokens.
$hexOf = fn($s) => implode(' ', str_split(bin2hex($s), 2));
$cfgText = '{"vendor_specific_enclosure_information": "' . $hexOf("\x07\x80AB\x00CF99X1234567890\x00\x00 155 SXP 24x6Gsec\x00") . '", "x": "SN=QQ7TEST12345;"}';
$st3 = st_anon_new([], 0);
st_anon_scan($st3, 'sescfg_sg3.json', $cfgText);
$out = st_anon_text($st3, 'sescfg_sg3.json', $cfgText);
preg_match('/"vendor_specific_enclosure_information": "([^"]+)"/', $out, $mm);
$bin = hex2bin(str_replace(' ', '', $mm[1] ?? ''));
t(!str_contains($bin, 'CF99X1234567890') && str_contains($bin, '155 SXP 24x6Gsec') && strlen($bin) === 40 && preg_match('/SN000\d0{9}/', $bin)
  && !str_contains($out, 'QQ7TEST12345'), 'serials in vendor-specific hex replaced, firmware strings and length kept: ' . json_encode($bin));
$m = load($d2);
t(count($m['direct']) === 7 && count(reset($m['shelves'])['drives']) === 12, 'anonymised data still loads: 7 direct, 12 in bays');
t(str_starts_with(array_values(array_filter($m['controllers'][0]['ports'], fn($p) => $p['expander']))[0]['attached_label'] ?? '', 'Shelf sg18 expander'), 'expander still matched to its shelf');
$x1 = st_anon_text($st, 'a', 'AA=500605B0000A1B3F;'); $x2 = st_anon_text($st, 'b', '"sas_address": "0x500605b0000a1b3f"');
t(substr($x1, 3, 16) === strtoupper(substr($x2, 18, 16)), 'the same address gets the same token in different files: ' . "$x1 $x2");
$st2 = st_anon_new(['tower', 'nas7'], 0);
$a = st_anon_text($st2, 'x', "aa:bb:cc:11:22:33 AA:BB:CC:11:22:33 00:00:00:00:00:00 tower NAS7.local 0x5000c500abcd1224 5000C500ABCD1225");
t($a === 'aa:bb:cc:00:00:01 AA:BB:CC:00:00:01 00:00:00:00:00:00 tower host1.local 0x5000c50000011224 5000C50000011225', 'MACs, hostnames and SAS addresses map consistently (default name "tower" kept): ' . $a);

// ---------------------------------------------------------------------------------------------------------
$section = 'ack-js';
// The webGUI never answers multipart/form-data POSTs: the Acknowledge script must send URL-encoded form data, with
// csrf_token in the body, and report failures next to the link rather than with alert().
$js = st_problems_js('TESTTOKEN');
t(str_contains($js, 'new URLSearchParams()') && !str_contains($js, 'FormData'), 'Acknowledge posts URL-encoded data (URLSearchParams), not FormData');
t(str_contains($js, "append('csrf_token'") && str_contains($js, '"TESTTOKEN"'), 'csrf_token is sent in the body');
t(!preg_match('/\balert\s*\(/', $js) && str_contains($js, 'Could not save: '), 'failures are shown next to the link, not with alert()');
t(str_contains(st_diag_html(), "href='/plugins/storage-topology/include/diagnostics.php?anon=1'"), 'diagnostics is a plain GET link');

// ---------------------------------------------------------------------------------------------------------
$section = 'render';
// Render the storage page from each fixture with a stub collector, as Unraid would include it.
$docroot = "$tmp/docroot";
copy_dir($plug, "$docroot/plugins/storage-topology");
foreach (glob("$docroot/plugins/storage-topology/scripts/*.sh") as $f) { file_put_contents($f, "#!/bin/bash\nexit 0\n"); chmod($f, 0755); }
$php = PHP_BINARY;
foreach (glob("$fx/*", GLOB_ONLYDIR) as $d) {
  $name = basename($d);
  $cmd = 'ST_TOPO_CACHE=' . escapeshellarg($d) . ' ST_ACK_FILE=' . escapeshellarg(ST_ACK_FILE) . ' ' . escapeshellarg($php) . ' '
       . escapeshellarg(__DIR__ . '/render.php') . ' ' . escapeshellarg($docroot) . ' StorageTopology.page';
  $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  $html = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
  proc_close($p);
  t($err === '', "$name renders without notices: $err");
  t(strlen($html) > 1000 && str_contains($html, '</div>'), "$name renders a page");
  if ($name === 'usb-short-ses') t(str_contains($html, 'No SAS controllers found. This page is for SAS HBAs/RAID controllers and disk shelves; USB enclosures are shown below.')
    && str_contains($html, 'USB enclosure: WD My Book Duo 25F6') && str_contains($html, 'Supports only the short status page'), 'usb page text');
  if ($name === 'emc-ktn-stl3') t(str_contains($html, '(shelf fault LED on)') && str_contains($html, 'PSU B'), 'emc page shows the fault LED note');
  if ($name === 'it-mode-sas3224') t(str_contains($html, 'Directly attached drives') && !str_contains($html, 'SN &middot;') && !str_contains($html, 'I/O modules'), 'it-mode page: direct table, no empty serial or IOM table');
}

rm_dir($tmp);
echo "tests: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);

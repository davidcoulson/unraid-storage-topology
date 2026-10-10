<?PHP
/* Diagnostics download (GET, read-only): a .tar.gz of what both pages collected, plus versions and a README.
 * ?anon=1 (the page's default) anonymises serial numbers, WWNs, SAS addresses, MACs and hostnames consistently
 * (include/anonymise.php) and reduces disks.ini/devs.ini to name, device, type and status.
 * The archive is built in a temporary folder that is removed afterwards.
 */
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/anonymise.php';

$anon = ($_GET['anon'] ?? '1') !== '0';
$em = dirname(__DIR__);
$cache = '/var/local/storage-topology';
// Make sure there is something recent to send (no-ops when the pages collected in the last minute).
exec("timeout 100 " . escapeshellarg("$em/scripts/collect.sh") . " 60 2>&1");
exec("timeout 90 " . escapeshellarg("$em/scripts/collect-net.sh") . " 60 2>&1");

$tmp = sys_get_temp_dir() . '/storage-topology-diag.' . bin2hex(random_bytes(6));
$base = 'storage-topology-diagnostics-' . date('Ymd-His') . ($anon ? '-anon' : '');
$root = "$tmp/$base";
register_shutdown_function(function () use ($tmp) { if (is_dir($tmp)) exec('rm -rf ' . escapeshellarg($tmp)); });
if (!@mkdir($root, 0700, true)) { http_response_code(500); exit('Could not create a temporary folder.'); }

// Copy one cache folder while holding its collector's lock, so a collection cannot swap it out mid-copy.
function diag_copy(string $from, string $to, string $lockFile): array {
  $files = [];
  $lock = @fopen($lockFile, 'c');
  if ($lock) flock($lock, LOCK_EX);
  if (is_dir($from)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if (!$f->isFile()) continue;
      $rel = substr($f->getPathname(), strlen($from) + 1);
      @mkdir(dirname("$to/$rel"), 0700, true);
      if (@copy($f->getPathname(), "$to/$rel")) $files[] = "$to/$rel";
    }
  }
  if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
  return $files;
}
$files = array_merge(diag_copy("$cache/current", "$root/storage", '/var/run/storage-topology.lock'),
                     diag_copy("$cache/net/current", "$root/network", '/var/run/storage-topology-net.lock'));

$run = function (string $cmd): string { $o = []; exec("timeout 20 $cmd 2>&1", $o); return trim(implode("\n", $o)); };
$plg = (string)@file_get_contents('/var/log/plugins/storage-topology.plg');
$storcli = trim((string)@file_get_contents("$cache/current/storcli.path"));
$ver = [
  'plugin' => preg_match('/<!ENTITY\s+version\s+"([^"]+)"/', $plg, $mm) ? $mm[1] : 'unknown',
  'unraid' => preg_match('/version="([^"]+)"/', (string)@file_get_contents('/etc/unraid-version'), $mm) ? $mm[1] : 'unknown',
  'kernel' => php_uname('r'),
  'sg_ses' => $run('sg_ses -V'),
  'storcli' => $storcli !== '' ? $run(escapeshellarg($storcli) . ' -v nolog') : 'not installed',
  'php' => PHP_VERSION,
  'anonymised' => $anon ? 'yes' : 'no',
];
$text = '';
foreach ($ver as $k => $v) $text .= str_pad("$k:", 12) . str_replace("\n", "\n            ", $v) . "\n";

if ($anon) {
  $st = st_anon_new(array_filter([gethostname() ?: '', trim((string)@file_get_contents('/etc/hostname'))]));
  $texts = [];
  foreach ($files as $f) {
    $name = basename($f);
    if ($name === 'disks.ini' || $name === 'devs.ini') { file_put_contents($f, st_anon_ini((string)file_get_contents($f), $name === 'devs.ini')); continue; }
    $texts[$f] = (string)file_get_contents($f);
    st_anon_scan($st, $name, $texts[$f]);
  }
  foreach ($texts as $f => $t) file_put_contents($f, st_anon_text($st, basename($f), $t));
}
file_put_contents("$root/versions.txt", $text);      // versions only, written after anonymising

// How each enclosure's SES data was read: sg_ses --json --join, or (when that failed) its pages one at a time.
$sesMethod = '';
$tm = [];
foreach (@file("$root/storage/timings", FILE_IGNORE_NEW_LINES) ?: [] as $l) { [$n, $rc] = array_pad(explode('|', $l), 2, ''); $tm[$n] = $rc; }
foreach ($tm as $n => $rc) if (preg_match('/^ses_(sg\d+)\.json$/', $n, $mm)) {
  $sg = $mm[1];
  $how = is_file("$root/storage/ses_$sg.short") ? 'short enclosure status page only'
    : (isset($tm["sesstat_$sg.json"]) ? "separate pages (--join " . ($rc === '0' ? 'gave no usable JSON' : "exit $rc") . ')' : '--join');
  $sesMethod .= "    $sg: $how\n";
}
if ($sesMethod === '') $sesMethod = "    (no SES enclosures)\n";

$readme = <<<TXT
Storage Topology diagnostics
============================

What is in here
- versions.txt: plugin, Unraid, kernel, sg_ses and storcli versions.
- storage/: what the Storage Topology page collected (scripts/collect.sh), read-only:
    ctrl.json, phys.json, encl.json, drives.json, drives_noencl.json  storcli "show" output (when storcli is installed)
    ses_sgN.json                 sg_ses --json --join per enclosure (ses_sgN.short: short-status-only enclosures)
    sescfg_sgN.json              sg_ses --json -p 1 (configuration page: subenclosures, element type names)
    sesstat_sgN.json, sesdesc_sgN.json, sesaes_sgN.json
                                 sg_ses --json -p 0x2 / 0x7 / 0xa, only when --join failed: the page joins these itself
    lsscsi.txt, lsblk.json       SCSI devices and block devices
    sas_hosts.txt, sas_phys.txt, expanders.txt, expander_phys.txt, end_devices.txt, enclosure_sysfs.txt, scsi_hosts.txt
                                 the kernel's SAS, SCSI host and enclosure view (sysfs)
    disks.ini, devs.ini          Unraid's disk list
    timings, *.err               how long each command took, and its error output
- network/: what the Network Topology page collected (scripts/collect-net.sh): ethtool output, lspci, mstflint query,
  bonding, VLANs, bridges, hwmon, counters (base/ holds the earlier collection used for "growth"), lldpctl JSON.

How the SES data of each enclosure was read:
{$sesMethod}
Anonymised: {$ver['anonymised']}
When anonymised, serial numbers, WWNs, SAS addresses, MAC addresses, NVMe EUIs, PCIe serial numbers, the server's
hostname, LLDP switch names and LLDP management addresses are replaced by tokens. The same value always gets the same
token, so the topology stays readable (SAS addresses keep their vendor prefix and last byte). disks.ini and devs.ini
are reduced to each disk's name, device, type and status. Interface names (eth0, bond0, br0, ...), PCI addresses
and disk device names (sdb, nvme0n1) are kept: the topology cannot be read without them.

Nothing here was changed on the server; all commands are read-only queries.
TXT;
file_put_contents("$root/README.txt", $readme . "\n");

$tgz = "$tmp/$base.tar.gz";
exec('tar -czf ' . escapeshellarg($tgz) . ' -C ' . escapeshellarg($tmp) . ' ' . escapeshellarg($base) . ' 2>&1', $o, $rc);
if ($rc !== 0 || !is_file($tgz)) { http_response_code(500); exit('Could not build the archive: ' . htmlspecialchars(implode(' ', $o), ENT_QUOTES)); }
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $base . '.tar.gz"');
header('Content-Length: ' . filesize($tgz));
header('Cache-Control: no-store');
readfile($tgz);

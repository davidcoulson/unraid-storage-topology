<?PHP
/* Storage topology model for StorageTopology.page.
 * Reads what scripts/collect.sh left in the cache folder (storcli JSON, sg_ses --join JSON or the SES pages it joins,
 * lsscsi, lsblk, the kernel's enclosure and SAS sysfs, disks.ini) and returns one array: controllers with ports and
 * connectors, shelves with I/O modules, power, cooling, sensors, cables and bays, drives mapped to Unraid disk names, drives
 * that are not in any bay (direct-attached), simple enclosures (USB boxes, short-status-only SES), and a list of
 * problems. No commands run here.
 *
 * NetApp DS424 shelves put useful data in the SES element descriptors (TP=..;SN=..;FW=..;). Other enclosures
 * still get status and sensors, just fewer labels.
 */

// ST_TOPO_CACHE lets tests/run.php render the page from a fixture folder.
if (!defined('TOPO_CACHE')) define('TOPO_CACHE', getenv('ST_TOPO_CACHE') ?: '/var/local/storage-topology/current');
// SATA links top out at 6 Gb/s (SATA III). Used as the far end's maximum for SATA drives, which the kernel
// identifies by the "sata" target protocol of their SAS end device (or the "ATA" SCSI vendor string).
const TOPO_SATA_MAX = 6.0;

function topo_json($file) {
  $raw = @file_get_contents($file);
  if ($raw === false || $raw === '') return null;
  $j = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
  return is_array($j) ? topo_trim_keys($j) : null;
}

// storcli JSON keys can carry stray spaces ("Device_Type ", "Enclosure /c0/e242 ").
function topo_trim_keys(array $a): array {
  $out = [];
  foreach ($a as $k => $v) $out[is_string($k) ? trim($k) : $k] = is_array($v) ? topo_trim_keys($v) : $v;
  return $out;
}

// "TP=9C;SN=ABC123;FW=0311;" -> [TP=>9C, SN=>ABC123, FW=>0311]. Empty values are dropped.
function topo_kv(string $s): array {
  $out = [];
  foreach (explode(';', $s) as $part) {
    if (!str_contains($part, '=')) continue;
    [$k, $v] = explode('=', $part, 2);
    $k = trim($k); $v = trim($v);
    if ($k !== '' && $v !== '') $out[$k] = $v;
  }
  return $out;
}

function topo_addr($a): string {
  if ($a === null || $a === '') return '';
  if (is_int($a) || ctype_digit((string)$a)) {
    $hex = function_exists('gmp_init') ? gmp_strval(gmp_init((string)$a, 10), 16) : base_convert((string)$a, 10, 16);
    return strtoupper(str_pad($hex, 16, '0', STR_PAD_LEFT));
  }
  return strtoupper(preg_replace('/^0x/i', '', trim((string)$a)));
}

// "12.0Gb/s", "6.0 Gbit" -> 12.0 / 6.0; anything else ("Unknown", "Phy disabled") -> 0.
function topo_gbps($s): float { return preg_match('/([\d.]+)\s*Gb/i', (string)$s, $m) ? (float)$m[1] : 0.0; }

function topo_rate_text(float $g): string { return $g ? rtrim(rtrim(number_format($g, 1), '0'), '.') : '-'; }

// SES element status -> ok | warn | crit | absent | unknown
function topo_ses_level(string $status): string {
  return match ($status) {
    'OK' => 'ok',
    'Noncritical' => 'warn',
    'Critical', 'Unrecoverable' => 'crit',
    'Not installed' => 'absent',
    default => 'unknown',
  };
}

function topo_worst(array $levels): string {
  foreach (['crit', 'warn', 'info', 'ok'] as $l) if (in_array($l, $levels, true)) return $l;
  return 'ok';
}

/* Short Enclosure Status diagnostic page (08h): simple enclosures return this one status byte instead of the
 * pages sg_ses --join needs. sg_ses only prints it raw ("Enclosure only supports Short enclosure status diagnostic
 * page, status=0x%x", the byte after the page code; sg3_utils src/sg_ses.c). We decode it the way FreeBSD's ses
 * driver does (sys/cam/scsi/scsi_enc_ses.c keeps it as the enclosure status and reads it with the SES_ENCSTAT_*
 * bits of sys/cam/scsi/scsi_ses.h), i.e. like byte 1 of the Enclosure Status page (02h), which sg_ses shows as
 * "INVOP=, INFO=, NON-CRIT=, CRIT=, UNRECOV=": INVOP 0x10, INFO 0x08, NON-CRIT 0x04, CRIT 0x02, UNRECOV 0x01. */
function topo_short_status(int $b): array {
  $bits = [];
  foreach ([0x01 => 'UNRECOV', 0x02 => 'CRIT', 0x04 => 'NON-CRIT', 0x08 => 'INFO', 0x10 => 'INVOP'] as $bit => $name) if ($b & $bit) $bits[] = $name;
  $level = ($b & 0x03) ? 'crit' : (($b & 0x04) ? 'warn' : (($b & 0x18) ? 'info' : 'ok'));
  return ['byte' => $b, 'bits' => $bits, 'level' => $level, 'text' => $bits ? implode(', ', $bits) : 'OK'];
}

function topo_load(string $dir = TOPO_CACHE): array {
  $m = ['collected' => (int)@file_get_contents("$dir/done"), 'timings' => [], 'errors' => [], 'controllers' => [], 'sg_by_hctl' => [],
        'shelves' => [], 'other_ses' => [], 'enclosures' => [], 'drives' => [], 'direct' => [], 'cables' => [], 'problems' => [],
        'detail' => true, 'storcli' => '', 'megaraid' => false, 'scsi_hosts' => [], 'lsscsi' => [], 'addr_shelf' => [], 'hphy_port' => [], 'no_sas' => false,
        'level' => 'ok'];
  if (!$m['collected']) { $m['errors'][] = 'No data collected yet.'; topo_problems($m); return $m; }
  foreach (@file("$dir/timings", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$name, $rc, $ms] = array_pad(explode('|', $l), 3, '');
    $m['timings'][$name] = ['rc' => (int)$rc, 'ms' => (int)$ms];
  }
  foreach ($m['timings'] as $name => ['rc' => $rc]) {
    if ($rc === 0) continue;
    // An enclosure that only has the short status page is supported (see topo_ses), not a failed collection.
    $err = trim((string)@file_get_contents("$dir/$name.err"));
    if (preg_match('/^ses_(sg\d+)\.json$/', $name, $mm) && (is_file("$dir/ses_{$mm[1]}.short") || stripos($err, 'only supports Short enclosure status') !== false)) continue;
    // sg_ses --join failed (e.g. crashed) but the pages it joins were read one by one (see topo_ses_join).
    if (preg_match('/^ses_(sg\d+)\.json$/', $name, $mm) && ($m['timings']["sesstat_{$mm[1]}.json"]['rc'] ?? -1) === 0) continue;
    // Optional pages: configuration (names, subenclosure revisions), element descriptors and additional element status.
    if (preg_match('/^ses(cfg|desc|aes)_/', $name)) continue;
    if ($name === 'drives_noencl.json' && stripos($err . @file_get_contents("$dir/$name"), 'No drive found') !== false) continue;
    $m['errors'][] = "$name: " . ((int)$rc === 124 ? 'timed out' : "exit $rc") . ($err ? " - $err" : '');
  }
  if (is_file("$dir/drives.skipped")) $m['detail'] = false;
  $m['storcli'] = trim((string)@file_get_contents("$dir/storcli.path"));
  $m['megaraid'] = is_file("$dir/megaraid_sas");
  foreach (@file("$dir/scsi_hosts.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$h, $proc] = array_pad(explode('|', $l), 2, '');
    if ($h !== '') $m['scsi_hosts'][$h] = trim($proc);
  }
  $m['lsscsi'] = topo_lsscsi($dir);

  $names = topo_unraid_names($dir);          // serial => [name, type, device]
  $k = topo_kernel($dir);                    // kernel SAS view: host PHYs, expanders, expander PHYs, end devices
  $labels = [];                              // SAS address => human label
  topo_controllers($dir, $m, $labels);
  topo_drives($dir, $m, $names);
  topo_sysfs_hba($dir, $m, $labels, $k, $names);
  topo_ses($dir, $m, $labels, $k, $names);
  topo_sysfs_drives($dir, $m, $names, $k);
  topo_shelf_drives($m);
  topo_shelf_paths($m, $k);
  topo_direct($m, $names, $k, $labels);
  topo_links($m, $labels);
  topo_connectors($m, $k);
  $m['no_sas'] = !$m['controllers'] && !$m['shelves'] && !$m['megaraid'] && $m['storcli'] === '' && !@filesize("$dir/sas_phys.txt");
  topo_problems($m);
  return $m;
}

// lsscsi -g: "[2:0:0:0]    disk    ATA      T-FORCE 1TB      3A0   /dev/sda   /dev/sg1" -> hctl => type, vendor, product, rev, dev, sg.
// Vendor (8), product (16) and revision (4) are fixed-width columns; NVMe lines have no revision.
function topo_lsscsi(string $dir): array {
  $out = [];
  foreach (@file("$dir/lsscsi.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    if (!preg_match('#^\[([^\]]+)\]\s+(\S+)\s+(.*?)\s+(/dev/\S+|-)\s+(/dev/sg\d+|-)\s*$#', $l, $mm)) continue;
    $id = $mm[3];
    if (strlen($id) >= 25 && $id[8] === ' ' && (strlen($id) === 25 || $id[25] === ' ')) {
      $vendor = substr($id, 0, 8); $product = substr($id, 9, 16); $rev = substr($id, 26);
    } else {
      $w = preg_split('/\s+/', $id);
      $vendor = array_shift($w) ?? ''; $rev = count($w) > 1 ? array_pop($w) : ''; $product = implode(' ', $w);
    }
    $out[$mm[1]] = ['hctl' => $mm[1], 'type' => $mm[2], 'vendor' => trim($vendor), 'product' => trim($product), 'rev' => trim((string)$rev),
                    'dev' => $mm[4] === '-' ? '' : basename($mm[4]), 'sg' => $mm[5] === '-' ? '' : basename($mm[5])];
  }
  return $out;
}

// serial => Unraid name from disks.ini (array, pools, boot) and devs.ini (unassigned), plus lsblk for /dev names.
function topo_unraid_names(string $dir): array {
  $out = [];
  $dev2serial = [];
  $lsblk = topo_json("$dir/lsblk.json");
  $blk = [];
  foreach ($lsblk['blockdevices'] ?? [] as $b) if (!empty($b['serial'])) { $dev2serial[$b['name']] = trim($b['serial']); $blk[$b['name']] = $b; }
  foreach (['disks.ini' => false, 'devs.ini' => true] as $f => $unassigned) {
    $ini = @parse_ini_file("$dir/$f", true, INI_SCANNER_RAW) ?: [];
    foreach ($ini as $key => $d) {
      $dev = $d['device'] ?? '';
      if ($dev === '') continue;
      $serial = $dev2serial[$dev] ?? '';
      if ($serial === '') continue;
      $out[$serial] = ['name' => $unassigned ? '' : ($d['name'] ?? $key), 'type' => $unassigned ? 'Unassigned' : ($d['type'] ?? ''),
                       'device' => $dev, 'status' => $d['status'] ?? '', 'spundown' => ($d['spundown'] ?? '0') === '1',
                       'temp' => is_numeric($d['temp'] ?? '') ? (int)$d['temp'] : null,
                       'errors' => is_numeric($d['numErrors'] ?? '') ? (int)$d['numErrors'] : null];
    }
  }
  foreach ($dev2serial as $dev => $serial) $out[$serial] ??= ['name' => '', 'type' => '', 'device' => $dev, 'status' => '', 'spundown' => false,
                                                              'temp' => null, 'errors' => null];
  // A drive the kernel sees through both of its ports (multipath cabling) has two block devices with one serial.
  $seen = array_count_values($dev2serial);
  foreach ($out as $serial => &$o) {
    $b = $blk[$o['device']] ?? [];
    $o['model'] = trim($b['model'] ?? ''); $o['size'] = $b['size'] ?? ''; $o['serial'] = $serial; $o['tran'] = $b['tran'] ?? '';
    $o['seen'] = $seen[$serial] ?? 1;
  }
  unset($o);
  return $out;
}

function topo_by_dev(array $names, string $dev): array {
  foreach ($names as $n) if ($n['device'] === $dev) return $n;
  return ['name' => '', 'type' => '', 'device' => $dev, 'status' => '', 'spundown' => false, 'temp' => null, 'errors' => null,
          'model' => '', 'size' => '', 'serial' => '', 'tran' => '', 'seen' => 1];
}

// "disk3 (/dev/sdb, SAMSUNG MZ7LM1T9)" for port and cable labels.
function topo_disk_label(array $n, string $dev): string {
  $what = array_filter(["/dev/$dev", $n['model'] ?? '']);
  return trim(($n['name'] ?? '') !== '' ? "{$n['name']} (" . implode(', ', $what) . ')' : implode(', ', $what));
}

// Kernel SAS topology (mpt3sas and other SAS transport drivers; megaraid_sas hides it).
function topo_kernel(string $dir): array {
  $k = ['hphy' => [], 'exp' => [], 'ephy' => [], 'end' => [], 'exp_by_addr' => []];
  foreach (@file("$dir/sas_phys.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$phy, $neg, $max, $sas, $port, $att, $hw] = array_pad(explode('|', $l), 7, '');
    if (!preg_match('/^phy-(\d+):(\d+)$/', $phy, $mm)) continue;
    $k['hphy'][$phy] = ['name' => $phy, 'host' => (int)$mm[1], 'n' => (int)$mm[2], 'rate' => topo_gbps($neg), 'max' => topo_gbps($max),
                        'max_hw' => topo_gbps($hw), 'neg' => trim($neg), 'sas' => topo_addr($sas), 'port' => $port, 'att' => $att];
  }
  foreach (@file("$dir/expanders.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$name, $sas, $ven, $prod, $rev, $parent] = array_pad(explode('|', $l), 6, '');
    if ($name === '') continue;
    $k['exp'][$name] = ['name' => $name, 'sas' => topo_addr($sas), 'vendor' => trim($ven), 'product' => trim($prod), 'rev' => trim($rev),
                        'parent' => $parent, 'phys' => []];
    if ($k['exp'][$name]['sas'] !== '') $k['exp_by_addr'][$k['exp'][$name]['sas']] = $name;
  }
  foreach (@file("$dir/expander_phys.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$phy, $neg, $max, $hw, $sas, $port, $att] = array_pad(explode('|', $l), 7, '');
    if (!preg_match('/^phy-(\d+):(\d+):(\d+)$/', $phy, $mm)) continue;
    $exp = "expander-{$mm[1]}:{$mm[2]}";
    $k['ephy'][$phy] = ['name' => $phy, 'exp' => $exp, 'n' => (int)$mm[3], 'rate' => topo_gbps($neg), 'max' => topo_gbps($max),
                        'max_hw' => topo_gbps($hw), 'sas' => topo_addr($sas), 'port' => $port, 'att' => $att];
    $k['exp'][$exp] ??= ['name' => $exp, 'sas' => '', 'vendor' => '', 'product' => '', 'rev' => '', 'parent' => '', 'phys' => []];
    $k['exp'][$exp]['phys'][] = $phy;
  }
  foreach (@file("$dir/end_devices.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$name, $sas, $proto, $port, $phys, $hctl, $type, $ven, $model, $blk] = array_pad(explode('|', $l), 10, '');
    if ($name === '') continue;
    $ven = trim($ven);
    $k['end'][$name] = ['name' => $name, 'sas' => topo_addr($sas), 'proto' => trim($proto), 'port' => $port,
                        'phys' => array_values(array_filter(explode(',', $phys))), 'hctl' => $hctl, 'type' => is_numeric($type) ? (int)$type : null,
                        'vendor' => $ven, 'model' => trim($model), 'block' => trim($blk),
                        'sata' => stripos($proto, 'sata') !== false || $ven === 'ATA'];
  }
  return $k;
}

// The expander's own link capability: its PHYs toward the HBA (linked, not in a downstream port), else all its PHYs.
function topo_exp_max(array $k, string $exp): float {
  $up = []; $all = [];
  foreach ($k['exp'][$exp]['phys'] ?? [] as $pn) {
    $p = $k['ephy'][$pn];
    $cap = $p['max_hw'] ?: $p['max'];
    if (!$cap) continue;
    $all[] = $cap;
    if ($p['port'] === '' && $p['rate'] > 0) $up[] = $cap;
  }
  return $up ? max($up) : ($all ? max($all) : 0.0);
}

// How an end device is linked: negotiated rate, this side's maximum, the far end's maximum, and what is expected.
function topo_end_link(array $k, array $e): array {
  $rates = []; $local = []; $via = ''; $phyNos = [];
  foreach ($e['phys'] as $pn) {
    $p = $k['hphy'][$pn] ?? $k['ephy'][$pn] ?? null;
    if (!$p) continue;
    $rates[] = $p['rate'];
    if ($p['max_hw'] ?: $p['max']) $local[] = $p['max_hw'] ?: $p['max'];
    $via = $p['exp'] ?? 'host';
    $phyNos[] = $p['n'];
  }
  $remote = $e['sata'] ? TOPO_SATA_MAX : 0.0;     // SAS end devices do not expose their PHYs' maximum: unknown
  $loc = $local ? min($local) : 0.0;
  return ['rate' => $rates ? min($rates) : 0.0, 'local' => $loc, 'remote' => $remote,
          'max' => $remote ? ($loc ? min($loc, $remote) : $remote) : 0.0, 'via' => $via, 'phys' => $phyNos];
}

// What a port's lanes should reach: the lower of both ends' maximum. When the far end is unknown, an expander is
// still held to this side's maximum (as before); an end device of unknown capability is not judged.
function topo_port_expect(float $local, float $remote, bool $expander): float {
  if ($remote > 0) return $local > 0 ? min($local, $remote) : $remote;
  return $expander ? $local : 0.0;
}

function topo_controllers(string $dir, array &$m, array &$labels): void {
  $ctrl = topo_json("$dir/ctrl.json");
  $phys = topo_json("$dir/phys.json");
  $physBy = [];
  foreach ($phys['Controllers'] ?? [] as $c) $physBy[$c['Command Status']['Controller'] ?? 0] = $c['Response Data']['PhyInfo'] ?? [];
  foreach ($ctrl['Controllers'] ?? [] as $c) {
    $id = $c['Command Status']['Controller'] ?? count($m['controllers']);
    $r = $c['Response Data'] ?? [];
    if (!$r) { $m['errors'][] = "controller $id: " . ($c['Command Status']['Description'] ?? 'no data'); continue; }
    $b = $r['Basics'] ?? []; $v = $r['Version'] ?? []; $s = $r['Status'] ?? []; $hw = $r['HwCfg'] ?? [];
    $sas = topo_addr($b['SAS Address'] ?? '');
    $ports = []; $phyList = [];
    foreach ($physBy[$id] ?? [] as $p) {
      $pno = (int)($p['PhyNo'] ?? 0);
      if (empty($p['Port_valid'])) { $phyList[$pno] = ['rate' => 0.0, 'port' => null]; continue; }
      $pn = (int)$p['Port'];
      $type = trim($p['Device_Type'] ?? '');
      $isExp = stripos($type, 'expander') !== false;
      $ports[$pn] ??= ['port' => $pn, 'phys' => [], 'rates' => [], 'attached' => topo_addr($p['SAS_Addr'] ?? ''), 'type' => $type,
                       'expander' => $isExp, 'local_max' => topo_gbps($p['MaxSpeed'] ?? '') ?: 12.0,
                       'remote_max' => stripos($type, 'sata') !== false ? TOPO_SATA_MAX : 0.0];
      $ports[$pn]['phys'][] = $pno;
      $ports[$pn]['rates'][] = topo_gbps($p['Link_Speed'] ?? '');
      $phyList[$pno] = ['rate' => topo_gbps($p['Link_Speed'] ?? ''), 'port' => $pn];
    }
    ksort($ports); ksort($phyList);
    // The HBA's SAS address is its base; port n is reported on cables as base + n.
    if ($sas !== '') foreach ($ports as $pn => $_) {
      $addr = strtoupper(str_pad(dechex(hexdec(substr($sas, -4)) + $pn), 4, '0', STR_PAD_LEFT));
      $labels[substr($sas, 0, -4) . $addr] = "HBA c$id port $pn";
    }
    // Some OEM and IT-mode firmware (e.g. Inspur's SAS3008 IT) gives storcli no controller status and no PHY data:
    // the status is then '' (not reported, not a fault) and topo_sysfs_hba fills the ports from the kernel's view.
    $m['controllers'][] = [
      'id' => $id, 'model' => $b['Model'] ?? '?', 'serial' => $b['Serial Number'] ?? '', 'sas' => $sas,
      'pci' => $b['PCI Address'] ?? '', 'fw_package' => $v['Firmware Package Build'] ?? '', 'fw' => $v['Firmware Version'] ?? '',
      'bios' => $v['Bios Version'] ?? '', 'driver' => trim(($v['Driver Name'] ?? '') . ' ' . ($v['Driver Version'] ?? '')),
      'status' => trim((string)($s['Controller Status'] ?? '')), 'no_phy_data' => !($physBy[$id] ?? []), 'personality' => trim($s['Current Personality'] ?? ''),
      'roc_temp' => $hw['ROC temperature(Degree Celsius)'] ?? null, 'memory' => $hw['On Board Memory Size'] ?? '',
      'pending_fw' => $r['Pending Images in Flash'] ?? null, 'cli' => $c['Command Status']['CLI Version'] ?? '',
      'ports' => array_values($ports), 'phy_list' => $phyList, 'unused_phys' => count(array_filter($phyList, fn($x) => $x['port'] === null)),
      'encl' => [], 'connector_names' => [],
    ];
  }
  $encl = topo_json("$dir/encl.json");
  foreach ($encl['Controllers'] ?? [] as $c) {
    $id = $c['Command Status']['Controller'] ?? 0;
    foreach ($c['Response Data'] ?? [] as $key => $e) {
      if (!preg_match('#/e(\d+)$#', $key, $mm)) continue;
      $info = $e['Information'] ?? [];
      $wwn = topo_addr($info['EnclLogicalID'] ?? '');
      foreach ($m['controllers'] as &$ct) if ($ct['id'] == $id) {
        $ct['encl'][$wwn] = [
          'eid' => (int)$mm[1], 'position' => $info['Position'] ?? '', 'status' => $info['Status'] ?? '',
          'serial' => $info['Enclosure Serial Number'] ?? '', 'rev' => $e['Inquiry Data']['Product Revision Level'] ?? '',
          'connector' => $info['Connector Name'] ?? '', 'multipath' => ($e['Properties'][0]['Port#'] ?? '') === 'Multipath'];
        // storcli names the connectors an enclosure is reached through ("C1 x4 & C2 x4"), not which PHYs they carry.
        if (trim($info['Connector Name'] ?? '') !== '') $ct['connector_names']["e{$mm[1]}"] = trim(preg_replace('/\s+/', ' ', $info['Connector Name']));
      }
      unset($ct);
    }
  }
}

function topo_drives(string $dir, array &$m, array $names): void {
  foreach (['drives.json', 'drives_noencl.json'] as $file) {
    $j = topo_json("$dir/$file");
    foreach ($j['Controllers'] ?? [] as $c) {
      $cid = $c['Command Status']['Controller'] ?? 0;
      if (($c['Command Status']['Status'] ?? '') === 'Failure') continue;     // e.g. "No drive found!" for /cx/sall
      $r = $c['Response Data'] ?? [];
      $rows = $r['Drive Information'] ?? null;     // brief form (drives spun down)
      if ($rows === null) {
        $rows = [];
        // Drives in an enclosure are /cN/eE/sS; drives without one (direct-attached) are /cN/sS.
        foreach ($r as $k => $v) if (preg_match('#^Drive (/c\d+(?:/e\d+)?/s\d+)$#', $k, $mm) && isset($v[0])) {
          $row = $v[0];
          $row['_detail'] = $r["Drive {$mm[1]} - Detailed Information"] ?? [];
          $row['_path'] = $mm[1];
          $rows[] = $row;
        }
      }
      foreach ($rows as $row) {
        // "EID:Slt" is "242:0", or " :3" for a drive that is not in an enclosure.
        [$e, $s] = array_pad(explode(':', (string)($row['EID:Slt'] ?? '')), 2, '');
        $eid = is_numeric(trim($e)) ? (int)$e : null;
        $slot = (int)$s;
        $path = $row['_path'] ?? ($eid === null ? "/c$cid/s$slot" : "/c$cid/e$eid/s$slot");
        $d = $row['_detail'] ?? [];
        $st = $d["Drive $path State"] ?? []; $at = $d["Drive $path Device attributes"] ?? []; $po = $d["Drive $path Policies/Settings"] ?? [];
        $serial = trim($at['SN'] ?? '');
        $u = $names[$serial] ?? null;
        $paths = [];
        foreach ($po['Port Information'] ?? [] as $p) $paths[] = ['port' => $p['Port'], 'status' => $p['Status'] ?? '',
          'rate' => topo_gbps($p['Linkspeed'] ?? ''), 'sas' => topo_addr($p['SAS address'] ?? '')];
        $temp = preg_match('/(-?\d+)C/', $st['Drive Temperature'] ?? '', $tm) ? (int)$tm[1] : null;
        $m['drives']["$cid:" . ($eid ?? '-') . ":$slot"] = [
          'ctrl' => $cid, 'eid' => $eid, 'slot' => $slot, 'did' => $row['DID'] ?? null, 'state' => $row['State'] ?? '',
          'type' => $row['Type'] ?? '', 'intf' => $row['Intf'] ?? '', 'med' => $row['Med'] ?? '', 'size' => $row['Size'] ?? '',
          'model' => trim($at['Model Number'] ?? $row['Model'] ?? ''), 'vendor' => trim($at['Manufacturer Id'] ?? ''),
          'serial' => $serial, 'fw' => $at['Firmware Revision'] ?? '', 'wwn' => $at['WWN'] ?? '',
          'max_rate' => topo_gbps($at['Device Speed'] ?? ''), 'rate' => topo_gbps($at['Link Speed'] ?? ''),
          'temp' => $temp, 'media_err' => $st['Media Error Count'] ?? null, 'other_err' => $st['Other Error Count'] ?? null,
          'pred_fail' => $st['Predictive Failure Count'] ?? null, 'smart_alert' => ($st['S.M.A.R.T alert flagged by drive'] ?? 'No') !== 'No',
          'paths' => $paths, 'multipath' => ($po['Multipath'] ?? '') === 'Yes',
          'unraid' => $u['name'] ?? '', 'unraid_type' => $u['type'] ?? '', 'dev' => $u['device'] ?? '', 'spundown' => $u['spundown'] ?? false,
        ];
      }
    }
  }
}

function topo_ses(string $dir, array &$m, array &$labels, array $k, array $names): void {
  $inq = [];
  foreach ($m['lsscsi'] as $hctl => $x) {
    if ($x['type'] !== 'enclosu' || $x['sg'] === '') continue;
    $inq[$x['sg']] = ['hctl' => $hctl, 'vendor' => $x['vendor'], 'product' => $x['product'], 'rev' => $x['rev']];
    $m['sg_by_hctl'][$hctl] = $x['sg'];
  }
  $eidByWwn = [];
  foreach ($m['controllers'] as $c) foreach ($c['encl'] as $wwn => $e) $eidByWwn[$wwn] = ['ctrl' => $c['id']] + $e;
  $encId = [];                                // SES device's SCSI address => enclosure logical ID (kernel enclosure class)
  foreach (@file("$dir/enclosure_sysfs.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$hctl, $id] = array_pad(explode('|', $l), 2, '');
    if ($id !== '') $encId[$hctl] ??= topo_addr($id);
  }
  // SES devices behind an expander: that expander belongs to the enclosure.
  $sesExp = [];
  foreach ($k['end'] as $e) if ($e['type'] === 13 && preg_match('/^port-(\d+):(\d+):\d+$/', $e['port'], $mm)) $sesExp[$e['hctl']] = "expander-{$mm[1]}:{$mm[2]}";

  foreach ($inq as $sg => $q) {
    $host = 'host' . explode(':', $q['hctl'])[0];
    $driver = $m['scsi_hosts'][$host] ?? '';
    $usb = in_array($driver, ['usb-storage', 'uas'], true);
    $short = is_file("$dir/ses_$sg.short") ? (string)file_get_contents("$dir/ses_$sg.short") : '';
    $j = topo_json("$dir/ses_$sg.json");
    $els = $j['join_of_diagnostic_pages']['element_list'] ?? null;
    // When --join failed, collect.sh fetched its pages one by one: join them here the way sg_ses would.
    if ($els === null && is_file("$dir/sesstat_$sg.json"))
      $els = topo_ses_join(topo_json("$dir/sescfg_$sg.json"), topo_json("$dir/sesstat_$sg.json"), topo_json("$dir/sesdesc_$sg.json"), topo_json("$dir/sesaes_$sg.json"));
    if ($els === null && $short === '') {
      $raw = (string)@file_get_contents("$dir/ses_$sg.json") . (string)@file_get_contents("$dir/ses_$sg.json.err");
      if (preg_match('/only supports Short enclosure status[^,]*, (status=0x[0-9a-f]+)/i', $raw, $mm)) $short = $mm[1];
    }
    if ($els === null && $short === '') {
      $m['errors'][] = stripos($raw, 'json') !== false && stripos($raw, 'unrecognized') !== false
        ? "$sg: sg_ses is too old for --json (sg3_utils 1.48 or newer needed)" : "$sg: no SES data";
      continue;
    }
    $g = ['psu' => [], 'fan' => [], 'temp' => [], 'volt' => [], 'amp' => [], 'esc' => [], 'conn' => [], 'slot' => [],
          'encl' => [], 'iomx' => []];
    [$subs, $headers] = topo_ses_config($dir, $sg, $els ?? []);
    $th = -1; $nth = 0;
    foreach ($els ?? [] as $e) {
      // Page 2 lists, per type descriptor header of page 1, one overall element and then the individual ones.
      if (empty($e['individual'])) { $th++; $nth = 0; continue; }
      $hd = $headers[$th] ?? null;
      $sd = $e['status_descriptor'] ?? [];
      $status = $sd['status']['meaning'] ?? '';
      // sg_ses writes "<null>" as the descriptor when the enclosure has no element descriptor page (7).
      $desc = ($e['descriptor'] ?? '') === '<null>' ? '' : trim((string)($e['descriptor'] ?? ''));
      $x = ['n' => $e['element_number'], 'status' => $status, 'level' => topo_ses_level($status),
            'desc' => $desc, 'kv' => topo_kv($desc),
            'prdfail' => !empty($sd['prdfail']), 'sub' => $hd['sub'] ?? null, 'name' => ''];
      // Without element descriptors (page 7), name elements from the type descriptor text: "Power Supply B",
      // "Temp. Sensor M 2". Descriptor-based names (NetApp) keep precedence.
      if ($x['desc'] === '' && $hd && $hd['text'] !== '') $x['name'] = $hd['text'] . ($hd['count'] > 1 ? ' ' . ($nth + 1) : '');
      $nth++;
      $sub = $subs[$x['sub'] ?? -1] ?? null;
      switch ($e['element_type']['i'] ?? -1) {
        case 1: case 23:
          $ae = $e['additional_element_status_descriptor'] ?? [];
          $x['slot'] = $ae['device_slot_number'] ?? $sd['slot_address'] ?? $x['n'];
          // Two fault bits: FAULT SENSED (the enclosure detected a problem) and FAULT REQSTD (host software set the
          // RQST FAULT control bit, e.g. a RAID tool or a script that lit the LED; the shelf itself found nothing).
          $x['fault_sensed'] = !empty($sd['fault_sensed']);
          $x['fault_reqstd'] = !empty($sd['fault_reqstd']);
          $x['fault'] = $x['fault_sensed'] || $x['fault_reqstd'];
          // How to clear the requested fault bit (shown, never run): "fault" is RQST FAULT of a (array) device slot
          // control element. --dev-slot-num needs the bay's device slot number from page 0Ah; without it, address the
          // element by type header index and element number (sg_ses --index=TH,N, sg3_utils 1.48 and later).
          $x['clear_cmd'] = !$x['fault_reqstd'] ? '' : 'sg_ses ' . (isset($ae['device_slot_number']) ? "--dev-slot-num={$ae['device_slot_number']}" : "--index=$th,{$x['n']}")
            . " --clear=fault /dev/$sg";
          $x['ident'] = !empty($sd['ident']);
          $x['phys'] = array_map(fn($p) => topo_addr($p['sas_address'] ?? ''), $ae['phy_descriptor_list'] ?? []);
          $g['slot'][] = $x; break;
        case 2:
          $x['flags'] = array_keys(array_filter(array_intersect_key($sd, array_flip(['dc_over_voltage', 'dc_under_voltage',
            'dc_over_current', 'fail', 'off', 'overtmp_fail', 'temp_warn', 'ac_fail', 'dc_fail']))));
          $x['fw'] = $x['kv']['FW'] ?? ($sub && $x['sub'] !== 0 ? $sub['rev'] : '');
          $x['pn'] = $x['kv']['PN'] ?? ($sub && $x['sub'] !== 0 ? $sub['product'] : '');
          $g['psu'][] = $x; break;
        case 3:
          $x['rpm'] = $sd['calculated_fan_speed'] ?? null; $x['code'] = $sd['actual_fan_code']['meaning'] ?? '';
          $x['flags'] = !empty($sd['fail']) ? ['fail'] : [];
          $g['fan'][] = $x; break;
        case 4:
          $x['value'] = preg_match('/(-?\d+)\s*C/', $sd['temperature']['meaning'] ?? '', $tm) ? (int)$tm[1] : null;
          $x['flags'] = array_keys(array_filter(array_intersect_key($sd, array_flip(['ot_failure', 'ot_warning', 'ut_failure', 'ut_warning', 'fail']))));
          $g['temp'][] = $x; break;
        case 7:
          $x['sub_info'] = $sub;
          $g['esc'][] = $x; break;
        case 14:
          $x['fault_ind'] = !empty($sd['failure_indication']); $x['warn_ind'] = !empty($sd['warning_indication']);
          $g['encl'][] = $x; break;
        case 18: case 19:
          $raw = $sd['voltage']['raw_value'] ?? $sd['current']['raw_value'] ?? null;
          // 0xFFFF means "no reading" (the HighPoint card reports it for every voltage, with bogus over-limit flags).
          $x['invalid'] = $raw === 65535;
          $x['value'] = $x['invalid'] ? null : (float)($sd['voltage']['value_in_volts'] ?? $sd['current']['value_in_amps'] ?? 0);
          $x['flags'] = $x['invalid'] ? [] : array_keys(array_filter(array_intersect_key($sd, array_flip(['warn_over', 'warn_under', 'crit_over', 'crit_under', 'fail']))));
          if ($x['invalid']) $x['level'] = 'unknown';
          $g[$e['element_type']['i'] == 18 ? 'volt' : 'amp'][] = $x; break;
        case 25:
          $x['type'] = $sd['connector_type']['meaning'] ?? '';
          $g['conn'][] = $x; break;
        case 131: $g['iomx'][] = $x; break;   // NetApp vendor element: SA= is the I/O module's expander address
      }
    }
    // Simple enclosures: USB drive boxes, and SES devices that only answer the short status page.
    if ($usb || $short !== '') {
      $m['enclosures'][$sg] = topo_simple_enclosure($dir, $m, $k, $names, $sg, $q, $driver, $usb, $short, $els === null ? null : $g, $sesExp);
      continue;
    }
    $enc = $g['encl'][0]['kv'] ?? [];
    // Enclosure elements: one for the whole shelf, or one per subenclosure (EMC: LCC A, LCC B and the chassis).
    $encLevel = topo_worst(array_intersect(array_column($g['encl'], 'level'), ['ok', 'warn', 'crit']));
    $encWorst = null;
    foreach ($g['encl'] as $x) if ($x['level'] === $encLevel) { $encWorst = $x; break; }
    $wwn = topo_addr($enc['WWN'] ?? '') ?: ($encId[$q['hctl']] ?? '');
    $known = $wwn !== '' && isset($eidByWwn[$wwn]);
    $sasSlots = array_filter($g['slot'], fn($x) => array_filter($x['phys'], fn($a) => trim($a, '0') !== ''));
    if (!$known && !$sasSlots) {
      // Not a SAS drive shelf (e.g. the HighPoint NVMe card's SES device).
      $m['other_ses'][$sg] = ['sg' => $sg] + $q + ['groups' => $g];
      continue;
    }
    $key = $wwn ?: $sg;
    if (isset($m['shelves'][$key])) {      // same shelf seen through the other IOM (or the other expander's SES device)
      $m['shelves'][$key]['sg'][] = $sg;
      if (isset($sesExp[$q['hctl']], $k['exp'][$sesExp[$q['hctl']]])) $m['addr_shelf'][$k['exp'][$sesExp[$q['hctl']]]['sas']] = $key;
      continue;
    }
    $ioms = [];
    foreach ($g['esc'] as $i => $x) {
      $sa = topo_addr($g['iomx'][$i]['kv']['SA'] ?? '');
      // Named from page 1 (EMC "LCC A": the Enclosure element of the controller's subenclosure, else "Controller A"),
      // with the subenclosure's revision as firmware; NetApp's descriptors give IOM A/B, FW, SN and PN.
      $name = 'IOM ' . chr(65 + $i);
      if ($x['name'] !== '') {
        $name = $x['name'];
        foreach ($g['encl'] as $en) if ($en['sub'] === $x['sub'] && $en['name'] !== '') $name = $en['name'];
      }
      $si = $x['sub_info'] ?? null;
      $ioms[] = ['name' => $name, 'status' => $x['status'], 'level' => $x['level'], 'fw' => $x['kv']['FW'] ?? ($si['rev'] ?? ''),
                 'serial' => $x['kv']['SN'] ?? '', 'pn' => $x['kv']['PN'] ?? ($si ? trim("{$si['vendor']} {$si['product']}") : ''), 'sas' => $sa];
    }
    $shelfId = ltrim($enc['ID'] ?? '', '0') ?: '?';
    $label = 'Shelf ' . ($enc['ID'] ?? $sg);
    foreach ($ioms as $iom) if ($iom['sas']) { $labels[$iom['sas']] = "$label {$iom['name']}"; $m['addr_shelf'][$iom['sas']] = $key; }
    // Kernel view: the expander this SES device sits behind is the shelf's expander.
    $expName = $sesExp[$q['hctl']] ?? '';
    if ($expName !== '' && ($ea = $k['exp'][$expName]['sas'] ?? '') !== '') {
      $labels[$ea] ??= "$label expander" . ($k['exp'][$expName]['product'] ? " ({$k['exp'][$expName]['product']})" : '');
      $m['addr_shelf'][$ea] = $key;
    }
    $m['shelves'][$key] = [
      'key' => $key, 'label' => $label, 'shelf_id' => $shelfId, 'sg' => [$sg], 'vendor' => $q['vendor'], 'product' => $q['product'],
      'rev' => $q['rev'], 'wwn' => $wwn, 'serial' => $enc['SN'] ?? ($eidByWwn[$wwn]['serial'] ?? ''), 'pn' => $enc['PN'] ?? '',
      'status' => $encWorst['status'] ?? ($g['encl'][0]['status'] ?? ''), 'level' => $encWorst ? $encLevel : ($g['encl'][0]['level'] ?? 'unknown'),
      'fault_led' => (bool)array_filter($g['encl'], fn($x) => $x['fault_ind'] || $x['warn_ind']), 'fault_explained' => false,
      'eid' => $eidByWwn[$wwn]['eid'] ?? null, 'ctrl' => $eidByWwn[$wwn]['ctrl'] ?? null,
      'multipath' => $eidByWwn[$wwn]['multipath'] ?? false,
      'ioms' => $ioms, 'groups' => $g, 'sub_names' => topo_sub_names($g),
    ];
  }
}

// Short names of an enclosure's subenclosures, for grouping sensors: EMC "LCC A", "LCC B", "Chassis", "PSU A", ...
// Empty when the enclosure has a single subenclosure or no type descriptor texts.
function topo_sub_names(array $g): array {
  $names = [];
  foreach (['encl', 'esc', 'psu'] as $k) foreach ($g[$k] as $x) if ($x['sub'] !== null && $x['name'] !== '' && !isset($names[$x['sub']]))
    $names[$x['sub']] = $x['name'] === 'Enclosure' ? 'Chassis' : topo_short_name($x['name']);
  $subs = [];
  foreach (['psu', 'fan', 'temp', 'volt', 'amp'] as $k) foreach ($g[$k] as $x) if ($x['sub'] !== null) $subs[$x['sub']] = true;
  return count($subs) > 1 ? $names : [];
}

/* The element list of "sg_ses --json --join", built from the separately fetched pages for enclosures where --join
 * fails (sg_ses 2.86, sg3_utils 1.48, crashes in --json --join on enclosures without an element descriptor page, e.g.
 * an EMC KTN-STL3: it reads the missing page's descriptor; 2.88 writes "<null>"): Configuration (1), Enclosure
 * Status (2), Element Descriptor (7, optional) and Additional Element Status (0Ah, optional). Follows sg_ses.c
 * (join_juggle_aes, join_aes_helper, join_array_display): one row per type descriptor header of page 1 (its overall
 * element, then its individual elements), each with its page 2 status descriptor and page 7 descriptor in the same
 * order ("<null>" when there is no page 7, as sg_ses 2.88 writes it). Page 0Ah descriptors are matched to rows:
 *   EIP=0          in order, to the individual elements of the element types page 0Ah covers (SES-3);
 *   EIP=1, EIIOE=1 element index counts all status descriptors, overall ones included;
 *   EIP=1, EIIOE=0 element index counts individual elements only; if that lands on an element type page 0Ah does not
 *                  cover, sg_ses switches to counting only individual elements of the covered types ("broken_ei").
 * sg_ses's page 0Ah JSON has the element index but not EIIOE, so EIIOE=1 is assumed only when the indexes fit it and
 * not EIIOE=0. Returns null when page 1 or 2 is missing or the two do not line up. */
function topo_ses_join(?array $cfg, ?array $st, ?array $ed, ?array $aes): ?array {
  $int = fn($v) => is_array($v) ? (int)($v['i'] ?? -1) : (int)$v;
  $hdrs = $cfg['configuration_diagnostic_page']['type_descriptor_header_and_text_list'] ?? null;
  $sts = $st['enclosure_status_diagnostic_page']['status_descriptor_list'] ?? null;
  if (!is_array($hdrs) || !is_array($sts) || count($hdrs) !== count($sts)) return null;
  $eds = $ed['element_descriptor_diagnostic_page']['element_descriptor_by_type_list'] ?? null;
  if (is_array($eds) && count($eds) !== count($hdrs)) $eds = null;
  // Element types page 0Ah reports on: device slot, ESC electronics, SCSI target/initiator port, array device slot, SAS expander.
  $aesTypes = [1, 7, 20, 21, 23, 24];
  $rows = []; $byTh = [];
  foreach ($hdrs as $k => $h) {
    $type = $int($h['element_type'] ?? -1);
    $s = $sts[$k];
    if ($int($s['element_type'] ?? -2) !== $type) return null;
    $et = is_array($s['element_type']) ? $s['element_type'] : ['i' => $type, 'meaning' => ''];
    $num = $int($h['number_of_possible_elements'] ?? 0);
    $e7 = $eds[$k] ?? null;
    $ind = $s['individual_status_element_list'] ?? [];
    $desc = fn($d) => $eds === null ? '<null>' : (string)($d ?? '');
    $rows[] = ['element_type' => $et, 'descriptor' => $desc($e7['overall_descriptor'] ?? null), 'element_number' => -1, 'overall' => 1,
               'individual' => false, 'status_descriptor' => $s['overall_descriptor'] ?? [], '_th' => $k, '_aes' => in_array($type, $aesTypes, true)];
    for ($j = 0; $j < $num; $j++) {
      $byTh[$k][$j] = count($rows);
      $rows[] = ['element_type' => $et, 'descriptor' => $desc($e7['element_descriptor'][$j]['descriptor'] ?? null), 'element_number' => $j,
                 'overall' => 0, 'individual' => true, 'status_descriptor' => $ind[$j] ?? [], '_th' => $k, '_aes' => in_array($type, $aesTypes, true)];
    }
  }
  // Index tables: all rows (EIIOE=1), individual rows (EIIOE=0), individual rows of the covered types (broken_ei).
  $all = array_keys($rows);
  $indiv = array_keys(array_filter($rows, fn($r) => $r['individual']));
  $aesIndiv = array_keys(array_filter($rows, fn($r) => $r['individual'] && $r['_aes']));
  // Page 0Ah groups its descriptors per covered type descriptor header, in page 1 order.
  $groups = $aes['additional_element_status_diagnostic_page']['additional_element_status_by_element_type_list'] ?? [];
  $ths = array_keys(array_filter($hdrs, fn($h) => in_array($int($h['element_type'] ?? -1), $aesTypes, true)));
  $items = []; $gi = 0;
  foreach ($ths as $k) {
    $g = $groups[$gi] ?? null;
    if (!$g || $int($g['element_type'] ?? -1) !== $int($hdrs[$k]['element_type']) || $int($g['subenclosure_identifier'] ?? 0) !== $int($hdrs[$k]['subenclosure_identifier'] ?? 0)) continue;
    $gi++;
    foreach ($g['additional_element_status_descriptor_list'] ?? [] as $j => $d)
      if (is_array($x = $d['additional_element_status_descriptor'] ?? null)) $items[] = ['th' => $k, 'j' => $j, 'type' => $int($g['element_type']), 'd' => $x];
  }
  $fits = function (array $table) use ($items, $rows) {
    foreach ($items as $it) {
      if (empty($it['d']['eip'])) continue;
      $r = $table[(int)($it['d']['element_index'] ?? -1)] ?? null;
      if ($r === null || !$rows[$r]['individual'] || $rows[$r]['element_type']['i'] !== $it['type']) return false;
    }
    return true;
  };
  $eiioe1 = !$fits($indiv) && $fits($all);
  $broken = false; $taken = [];
  foreach ($items as $it) {
    $d = $it['d'];
    if (empty($d['eip'])) $r = $byTh[$it['th']][$it['j']] ?? null;
    elseif ($eiioe1) $r = $all[(int)$d['element_index']] ?? null;
    else {
      $ei = (int)($d['element_index'] ?? -1);
      $r = ($broken ? $aesIndiv : $indiv)[$ei] ?? null;
      if ($r !== null && !$broken && !$rows[$r]['_aes']) { $broken = true; $r = $aesIndiv[$ei] ?? null; }
    }
    if ($r === null || isset($taken[$r])) continue;          // sg_ses keeps the first descriptor for an element
    $taken[$r] = true;
    // The join writes the descriptor without page 0Ah's own header fields. Descriptors flagged invalid (e.g. an empty
    // bay) carry nothing else in the page's JSON; the join would decode their bytes anyway, the page does not need them.
    $x = array_diff_key($d, array_flip(['invalid', 'eip', 'protocol_identifier', 'element_index']));
    if ($x) $rows[$r]['additional_element_status_descriptor'] = $x;
  }
  foreach ($rows as &$r) unset($r['_th'], $r['_aes']);
  unset($r);
  return $rows;
}

// SES configuration page (sg_ses --json -p 1): subenclosures by id (vendor, product, revision) and the type
// descriptor headers in page order (element type, subenclosure, number of elements, text). Used only when the
// headers line up with the overall elements of the status page.
function topo_ses_config(string $dir, string $sg, array $els): array {
  $j = topo_json("$dir/sescfg_$sg.json")['configuration_diagnostic_page'] ?? null;
  if (!$j) return [[], []];
  $int = fn($v) => is_array($v) ? (int)($v['i'] ?? -1) : (int)$v;
  $subs = [];
  foreach ($j['enclosure_descriptor_list'] ?? [] as $d) {
    $id = $int($d['subenclosure_identifier'] ?? -1);
    $subs[$id] = ['id' => $id, 'vendor' => trim($d['enclosure_vendor_identification'] ?? ''), 'product' => trim($d['product_identification'] ?? ''),
                  'rev' => trim($d['product_revision_level'] ?? ''), 'wwn' => topo_addr($d['enclosure_logical_identifier'] ?? '')];
  }
  $headers = [];
  foreach ($j['type_descriptor_header_and_text_list'] ?? [] as $t)
    $headers[] = ['type' => $int($t['element_type'] ?? -1), 'sub' => $int($t['subenclosure_identifier'] ?? 0),
                  'count' => $int($t['number_of_possible_elements'] ?? 0), 'text' => trim((string)($t['text'] ?? ''))];
  $overall = array_values(array_filter($els, fn($e) => empty($e['individual'])));
  if (count($overall) !== count($headers)) return [$subs, []];
  foreach ($headers as $i => $hd) if (($overall[$i]['element_type']['i'] ?? -2) !== $hd['type']) return [$subs, []];
  return [$subs, $headers];
}

// A simple enclosure: overall status (short status byte, or the elements when it has full SES), and its disks:
// other LUNs of the same SCSI target (USB boxes), bays from the kernel's enclosure class, disks behind the same
// expander, or as a last resort the disks on the same SCSI host.
function topo_simple_enclosure(string $dir, array $m, array $k, array $names, string $sg, array $q, string $driver, bool $usb,
                               string $short, ?array $g, array $sesExp): array {
  $st = null; $level = 'unknown'; $text = '';
  if ($short !== '' && preg_match('/status=0x([0-9a-f]+)/i', $short, $mm)) {
    $st = topo_short_status(hexdec($mm[1]));
    $level = $st['level']; $text = $st['text'];
  } elseif ($g !== null) {
    $lv = [];
    foreach (['encl', 'psu', 'fan', 'temp', 'volt', 'amp'] as $kk) foreach ($g[$kk] as $x) $lv[] = $x['level'];
    $level = topo_worst(array_intersect($lv, ['ok', 'warn', 'crit']));
    $text = $g['encl'][0]['status'] ?? ($level === 'ok' ? 'OK' : $level);
  }
  [$h, $c, $t] = array_pad(explode(':', $q['hctl']), 3, '');
  $disks = []; $source = '';
  $add = function (string $dev, string $where = '') use (&$disks, $names, $m) {
    if ($dev === '') return;
    $n = topo_by_dev($names, $dev);
    $ls = null;
    foreach ($m['lsscsi'] as $x) if ($x['dev'] === $dev) $ls = $x;
    $disks[$dev] = ['dev' => $dev, 'where' => $where, 'unraid' => $n['name'], 'unraid_type' => $n['type'], 'model' => $n['model'] ?: trim(($ls['vendor'] ?? '') . ' ' . ($ls['product'] ?? '')),
                    'size' => $n['size'], 'temp' => $n['temp'], 'serial' => $n['serial'], 'spundown' => $n['spundown'],
                    'vendor' => $ls['vendor'] ?? '', 'product' => $ls['product'] ?? ''];
  };
  foreach ($m['lsscsi'] as $hctl => $x) if ($x['type'] === 'disk' && str_starts_with($hctl, "$h:$c:$t:")) $add($x['dev'], 'LUN ' . substr($hctl, strlen("$h:$c:$t:")));
  if ($disks) $source = 'lun';
  $bays = [];
  if (!$disks) {
    foreach (@file("$dir/enclosure_sysfs.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
      [$hc, $id, $comp, $slot, $status, $fault, $locate, $blk] = array_pad(explode('|', $l), 8, '');
      if ($hc !== $q['hctl']) continue;
      $bays[] = ['slot' => is_numeric($slot) ? (int)$slot : $comp, 'status' => $status, 'fault' => $fault === '1', 'dev' => $blk];
      if ($blk !== '') $add($blk, 'bay ' . (is_numeric($slot) ? (int)$slot : $comp));
    }
    if ($bays) $source = 'bays';
  }
  if (!$disks && isset($sesExp[$q['hctl']])) {
    $exp = $sesExp[$q['hctl']];
    foreach ($k['end'] as $e) if ($e['block'] !== '' && preg_match('/^port-(\d+):(\d+):\d+$/', $e['port'], $mm) && "expander-{$mm[1]}:{$mm[2]}" === $exp) $add($e['block']);
    if ($disks) $source = 'expander';
  }
  if (!$disks && !$usb) {
    foreach ($m['lsscsi'] as $hctl => $x) if ($x['type'] === 'disk' && str_starts_with($hctl, "$h:")) $add($x['dev']);
    if ($disks) $source = 'host';
  }
  $first = reset($disks) ?: null;
  $name = $usb && $first && ($first['vendor'] || $first['product']) ? trim("{$first['vendor']} {$first['product']}") : trim("{$q['vendor']} {$q['product']}");
  return ['sg' => $sg, 'hctl' => $q['hctl'], 'vendor' => $q['vendor'], 'product' => $q['product'], 'rev' => $q['rev'], 'name' => $name,
          'driver' => $driver, 'usb' => $usb, 'short_status_only' => $st !== null, 'status' => $st, 'level' => $level, 'status_text' => $text,
          'groups' => $g, 'disks' => array_values($disks), 'bays' => $bays, 'disk_source' => $source];
}

// PCI address in one form for both sources: storcli "00:01:00:00" and sysfs "0000:01:00.0" -> "01:00.0"; '' if neither.
function topo_pci(string $a): string {
  $a = trim($a);
  if (preg_match('/^[0-9a-f]{1,4}:([0-9a-f]{1,2}):([0-9a-f]{1,2})[:.]([0-9a-f]{1,2})$/i', $a, $mm))
    return sprintf('%02x:%02x.%x', hexdec($mm[1]), hexdec($mm[2]), hexdec($mm[3]));
  return '';
}

// Without storcli (e.g. an LSI HBA in IT mode on mpt3sas), describe each SAS HBA from the kernel's sas_phy data.
// HBAs storcli describes (same SAS or PCI address) are skipped, except that one storcli gave no ports gets them from here.
function topo_sysfs_hba(string $dir, array &$m, array &$labels, array $k, array $names): void {
  $hosts = [];
  foreach (@file("$dir/sas_hosts.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$h, $driver, $board, $fw, $pci] = array_pad(explode('|', $l), 5, '');
    $hosts[$h] = ['driver' => trim($driver), 'board' => trim($board, " \t\""), 'fw' => trim($fw), 'pci' => topo_pci($pci)];
  }
  $byHost = [];
  foreach ($k['hphy'] as $p) $byHost[$p['host']][$p['n']] = $p;
  ksort($byHost);
  foreach ($byHost as $h => $phys) {
    ksort($phys);
    $hi = $hosts["host$h"] ?? [];
    if (($hi['driver'] ?? '') === 'megaraid_sas') continue;   // megaraid hides its SAS layer; storcli is needed there
    $sas = reset($phys)['sas'] ?? '';
    $ci = null;
    foreach ($m['controllers'] as $i => $c)
      if (empty($c['sysfs']) && (($sas !== '' && $c['sas'] === $sas) || (($hi['pci'] ?? '') !== '' && topo_pci($c['pci']) === $hi['pci']))) $ci = $i;
    if ($ci !== null) {                                           // already described by storcli
      if ($m['controllers'][$ci]['ports']) continue;
      [$ports, $phyList] = topo_kernel_ports($k, $phys, $labels, $names);
      if (!$ports) continue;
      $c = &$m['controllers'][$ci];
      foreach ($ports as $pt) foreach ($pt['phys'] as $n) $m['hphy_port']["phy-$h:$n"] = "HBA c{$c['id']} port {$pt['port']}";
      $c['ports'] = array_values($ports); $c['phy_list'] = $phyList; $c['kernel_ports'] = true;
      $c['unused_phys'] = count(array_filter($phyList, fn($x) => $x['port'] === null));
      unset($c);
      continue;
    }
    [$ports, $phyList] = topo_kernel_ports($k, $phys, $labels, $names);
    if (!$ports) continue;
    foreach ($ports as $pt) foreach ($pt['phys'] as $n) $m['hphy_port']["phy-$h:$n"] = "HBA c$h port {$pt['port']}";
    $m['controllers'][] = [
      'id' => (int)$h, 'model' => $hi['board'] ?: ($hi['driver'] ?: 'SAS HBA') . " (host$h)", 'serial' => '', 'sas' => $sas, 'pci' => '',
      'fw_package' => '', 'fw' => $hi['fw'] ?? '', 'bios' => '', 'driver' => $hi['driver'] ?? '', 'status' => 'Optimal',
      'personality' => 'HBA (kernel view, storcli not installed)', 'roc_temp' => null, 'memory' => '', 'pending_fw' => null, 'cli' => '',
      'ports' => array_values($ports), 'phy_list' => $phyList, 'unused_phys' => count(array_filter($phyList, fn($x) => $x['port'] === null)),
      'encl' => [], 'connector_names' => [], 'sysfs' => true,
    ];
  }
}

// One SAS host's ports from its kernel PHYs (sas_phy, sas_port): [ports keyed by sas_port, PHY number => rate and port].
function topo_kernel_ports(array $k, array $phys, array &$labels, array $names): array {
  $ports = []; $phyList = [];
  foreach ($phys as $p) {
    if ($p['port'] === '') { $phyList[$p['n']] = ['rate' => 0.0, 'port' => null]; continue; }
    [$attName, $attAddr] = array_pad(explode('=', $p['att'], 2), 2, '');
    // A port attached to its own address is a virtual management endpoint (e.g. a PCIe switch card's SES), not a cable.
    if (topo_addr($attAddr) === $p['sas']) continue;
    if (!isset($ports[$p['port']])) {
      $isExp = str_starts_with($attName, 'expander');
      $end = $k['end'][$attName] ?? null;
      $type = $isExp ? 'Expander' : ($attName ? 'End device' . ($end ? ($end['sata'] ? ' (SATA)' : ' (SAS)') : '') : '');
      $remote = $isExp ? topo_exp_max($k, $attName) : ($end && $end['sata'] ? TOPO_SATA_MAX : 0.0);
      $ports[$p['port']] = ['port' => count($ports), 'phys' => [], 'rates' => [], 'attached' => topo_addr($attAddr), 'type' => $type,
                            'expander' => $isExp, 'att_name' => $attName, 'local_max' => 0.0, 'remote_max' => $remote,
                            'remote_what' => $isExp ? 'expander' : ($end && $end['sata'] ? 'SATA drive' : '')];
      if ($isExp && ($e = $k['exp'][$attName] ?? null) && $e['product']) $ports[$p['port']]['type'] .= " ({$e['product']})";
      // A drive straight on the HBA: label the port with the disk instead of a bare SAS address.
      if ($end && $end['block'] !== '') $labels[topo_addr($attAddr)] = topo_disk_label(topo_by_dev($names, $end['block']), $end['block']);
    }
    $pt = &$ports[$p['port']];
    $pt['phys'][] = $p['n'];
    $pt['rates'][] = $p['rate'];
    $cap = $p['max_hw'] ?: $p['max'];
    if ($cap) $pt['local_max'] = $pt['local_max'] ? min($pt['local_max'], $cap) : $cap;
    unset($pt);
    $phyList[$p['n']] = ['rate' => $p['rate'], 'port' => $ports[$p['port']]['port']];
  }
  return [$ports, $phyList];
}

// Bays of shelves storcli does not know: the kernel's enclosure driver links each bay to its disk.
function topo_sysfs_drives(string $dir, array &$m, array $names, array $k): void {
  $endByBlk = [];
  foreach ($k['end'] as $e) if ($e['block'] !== '') $endByBlk[$e['block']] = $e;
  foreach (@file("$dir/enclosure_sysfs.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$hctl, $id, $comp, $slot, $status, $fault, $locate, $blk] = array_pad(explode('|', $l), 8, '');
    // The kernel names an enclosure by its SES device's SCSI address; find the shelf that SES device belongs to.
    $sg = $m['sg_by_hctl'][$hctl] ?? '';
    $key = null;
    foreach ($m['shelves'] as $kk => $s) if (in_array($sg, $s['sg'], true)) $key = $kk;
    if ($key === null || $m['shelves'][$key]['eid'] !== null || $blk === '') continue;
    $slotNo = is_numeric($slot) ? (int)$slot : (is_numeric($comp) ? (int)$comp : $comp);
    $m['drives']["sysfs:$key:$slotNo"] = ['shelf' => $key, 'slot' => $slotNo] + topo_kernel_drive(topo_by_dev($names, $blk), $blk, $endByBlk[$blk] ?? null, $k);
  }
}

// A drive seen only through the kernel: Unraid name and size from disks.ini/lsblk, link rate from its SAS end device.
function topo_kernel_drive(array $n, string $blk, ?array $end, array $k): array {
  $link = $end ? topo_end_link($k, $end) : ['rate' => 0.0, 'max' => 0.0, 'local' => 0.0, 'remote' => 0.0, 'via' => '', 'phys' => []];
  return [
    'ctrl' => null, 'eid' => null, 'shelf' => null, 'slot' => null, 'did' => null, 'state' => '', 'type' => '',
    'intf' => $end ? ($end['sata'] ? 'SATA' : 'SAS') : strtoupper($n['tran'] ?? ''), 'med' => '',
    'size' => $n['size'], 'model' => $n['model'] ?: ($end['model'] ?? ''), 'vendor' => $end['vendor'] ?? '', 'serial' => $n['serial'], 'fw' => '', 'wwn' => '',
    'max_rate' => $link['max'], 'rate' => $link['rate'], 'link' => $link, 'temp' => $n['temp'], 'media_err' => null, 'other_err' => null, 'pred_fail' => null,
    'smart_alert' => false, 'paths' => [], 'multipath' => false, 'unraid_errors' => $n['errors'], 'kernel_paths' => $n['seen'] ?? 1,
    'unraid' => $n['name'], 'unraid_type' => $n['type'], 'dev' => $blk, 'spundown' => $n['spundown'],
  ];
}

// Attach drives to their shelf: storcli drives by controller + enclosure ID, kernel-view drives by enclosure WWN.
function topo_shelf_drives(array &$m): void {
  foreach ($m['shelves'] as $key => &$s) {
    $s['drives'] = [];
    foreach ($m['drives'] as $id => $d) {
      $mine = $s['eid'] !== null ? ($d['eid'] === $s['eid'] && $d['ctrl'] === $s['ctrl']) : (($d['shelf'] ?? null) === $key);
      if ($mine) { $s['drives'][$d['slot']] = $d; $m['drives'][$id]['placed'] = true; }
    }
    ksort($s['drives']);
  }
  unset($s);
}

/* Whether the server reaches a shelf over one path or two (both I/O modules cabled to it): 'multi', 'single', or null
 * when nothing tells. storcli: the enclosure's Port# is "Multipath", or a drive has two paths. Kernel view: the
 * server sees two of the shelf's expanders, or a drive twice (two block devices with one serial); one expander, or
 * drives seen once, is a single path. */
function topo_shelf_paths(array &$m, array $k): void {
  foreach ($m['shelves'] as $key => &$s) {
    if ($s['eid'] !== null) {
      $two = array_filter($s['drives'], fn($d) => $d['multipath'] || count(array_filter($d['paths'], fn($p) => $p['status'] === 'Active')) > 1);
      $s['paths'] = $s['multipath'] || $two ? 'multi' : 'single';
      $s['paths_why'] = $s['multipath'] ? 'storcli reports the enclosure as Multipath' : ($two ? 'drives report two paths' : 'storcli reports one path');
      continue;
    }
    $exps = [];
    foreach ($m['addr_shelf'] as $a => $sk) if ($sk === $key && isset($k['exp_by_addr'][$a])) $exps[$k['exp_by_addr'][$a]] = true;
    $two = array_filter($s['drives'], fn($d) => ($d['kernel_paths'] ?? 1) > 1);
    if (count($exps) > 1 || $two) { $s['paths'] = 'multi'; $s['paths_why'] = count($exps) > 1 ? 'the server sees both I/O modules\' expanders' : 'drives are seen twice'; }
    elseif ($exps || $s['drives']) { $s['paths'] = 'single'; $s['paths_why'] = $exps ? 'the server sees one of its expanders' : 'drives are seen once'; }
    else { $s['paths'] = null; $s['paths_why'] = ''; }
  }
  unset($s);
}

// Drives that are in no shelf bay: storcli drives outside any enclosure the page can read (no enclosure, or a
// virtual one such as EID 252 for a backplane without SES), and kernel end devices straight on an HBA PHY or on an
// expander PHY that no SES bay points to.
function topo_direct(array &$m, array $names, array $k, array $labels): void {
  $placedDevs = [];
  foreach ($m['drives'] as $d) if (!empty($d['placed']) && $d['dev'] !== '') $placedDevs[$d['dev']] = true;
  foreach ($m['enclosures'] as $e) foreach ($e['disks'] as $d) $placedDevs[$d['dev']] = true;
  foreach ($m['drives'] as $id => $d) {
    if (!empty($d['placed']) || $d['ctrl'] === null) continue;
    $m['direct'][] = ['drive' => $id, 'where' => "c{$d['ctrl']} " . ($d['eid'] !== null ? "e{$d['eid']} " : '') . "slot {$d['slot']}",
                      'note' => $d['eid'] !== null ? "Enclosure e{$d['eid']} has no SES device this page can read (often a backplane without SES)." : 'Not in an enclosure.'];
  }
  if (!$m['controllers'] || !array_filter(array_column($m['controllers'], 'sysfs'))) return;
  foreach ($k['end'] as $e) {
    if ($e['block'] === '' || $e['type'] !== 0 || isset($placedDevs[$e['block']])) continue;
    $d = topo_kernel_drive(topo_by_dev($names, $e['block']), $e['block'], $e, $k);
    $where = '';
    if ($d['link']['via'] === 'host') {
      foreach ($e['phys'] as $pn) if (isset($m['hphy_port'][$pn])) $where = $m['hphy_port'][$pn];
    } elseif ($d['link']['via'] !== '') {
      $ea = $k['exp'][$d['link']['via']]['sas'] ?? '';
      $where = ($labels[$ea] ?? ('Expander ' . ($ea ?: $d['link']['via']))) . ' PHY ' . implode(', ', $d['link']['phys']);
    }
    $id = "sysfs:direct:{$e['block']}";
    $m['drives'][$id] = $d;
    $m['direct'][] = ['drive' => $id, 'where' => $where, 'note' => $d['link']['via'] === 'host' ? 'Directly on the HBA.' : 'Behind an expander, in no enclosure bay.'];
  }
}

// Cables from the shelves' SAS connector elements (NetApp: 4 per IOM, AA= is the address at the other end).
function topo_links(array &$m, array $labels): void {
  $seen = [];
  foreach ($m['shelves'] as &$s) {
    $perIom = max(1, intdiv(count($s['groups']['conn']), max(1, count($s['ioms']))));
    foreach ($s['groups']['conn'] as $i => $c) {
      $iom = $s['ioms'][intdiv($i, $perIom)]['name'] ?? '';
      $c['local'] = trim("{$s['label']} $iom port " . ($i % $perIom + 1));
      $c['remote_addr'] = topo_addr($c['kv']['AA'] ?? '');
      $c['remote'] = $labels[$c['remote_addr']] ?? ($c['remote_addr'] ? $c['remote_addr'] : '');
      $c['cable'] = trim(($c['kv']['VN'] ?? '') . ' ' . ($c['kv']['PN'] ?? ''));
      $c['cable_sn'] = $c['kv']['SN'] ?? '';
      $s['groups']['conn'][$i] = $c;
      if ($c['level'] === 'absent' || $c['remote_addr'] === '') continue;
      // A shelf-to-shelf cable is reported by both shelves; keep one row and fill in the far port.
      $id = $c['cable_sn'] ?: "{$c['local']}>{$c['remote']}";
      if (isset($seen[$id])) {
        $m['cables'][$seen[$id]]['remote'] = $c['local'];
        $m['cables'][$seen[$id]]['levels'][] = $c['level'];
        continue;
      }
      $seen[$id] = count($m['cables']);
      $m['cables'][] = ['local' => $c['local'], 'remote' => $c['remote'], 'cable' => $c['cable'], 'sn' => $c['cable_sn'],
                        'status' => $c['status'], 'levels' => [$c['level']], 'to_hba' => str_starts_with($c['remote'], 'HBA')];
    }
  }
  unset($s);
  foreach ($m['controllers'] as &$c) foreach ($c['ports'] as &$p) {
    $p['attached_label'] = $labels[$p['attached']] ?? $p['attached'];
    $p['expect'] = topo_port_expect($p['local_max'], $p['remote_max'], $p['expander']);
    $p['slow'] = $p['rates'] && (min($p['rates']) < $p['expect'] || min($p['rates']) < max($p['rates']));
  }
  unset($c, $p);
  usort($m['cables'], fn($a, $b) => [!$a['to_hba'], $a['local']] <=> [!$b['to_hba'], $b['local']]);
}

/* HBA connectors. LSI/Broadcom HBAs carry 4 PHYs per SFF-8643/8644 (or 4 lanes of an SFF-8654) connector, PHYs 0-3
 * on the first, 4-7 on the next, and so on. Neither the kernel nor storcli reports which PHYs a connector carries,
 * so the groups and their names (C0, C1, ...) are derived and may not match the labels printed on the card. */
function topo_connectors(array &$m, array $k): void {
  foreach ($m['controllers'] as &$c) {
    $byPort = [];
    foreach ($c['ports'] as $i => $p) $byPort[$p['port']] = $i;
    $groups = [];
    foreach ($c['phy_list'] as $n => $x) {
      $g = intdiv($n, 4);
      $groups[$g] ??= ['n' => $g, 'label' => "C$g", 'phys' => [], 'linked' => [], 'ports' => [], 'attached' => [], 'expanders' => []];
      $groups[$g]['phys'][] = $n;
      if ($x['port'] === null) continue;
      $groups[$g]['linked'][$n] = $x['rate'];
      $pi = $byPort[$x['port']] ?? null;
      if ($pi === null) continue;
      $p = $c['ports'][$pi];
      $groups[$g]['ports'][$p['port']] = $p['port'];
      $groups[$g]['attached'][$p['attached_label']] = $p['attached_label'];
      if ($p['expander']) $groups[$g]['expanders'][$p['attached']] = $p['attached'];
    }
    ksort($groups);
    foreach ($groups as &$g) {
      $g['first'] = min($g['phys']); $g['last'] = max($g['phys']);
      $g['phy_text'] = $g['first'] === $g['last'] ? "PHY {$g['first']}" : "PHYs {$g['first']}-{$g['last']}";
      $nl = count($g['linked']);
      // A breakout cable to single drives may use only some lanes; lanes missing on a link to an expander mean a bad cable or connector.
      $g['partial'] = $nl > 0 && $nl < count($g['phys']) && $g['expanders'];
      $g['level'] = $nl === 0 ? 'absent' : ($g['partial'] ? 'warn' : 'ok');
    }
    unset($g);
    $c['connectors'] = array_values($groups);
    foreach ($c['ports'] as &$p) {
      $p['connectors'] = [];
      foreach ($c['connectors'] as $g) if (in_array($p['port'], $g['ports'], true)) $p['connectors'][] = $g['label'];
      $p['wide'] = count($p['phys']) > 1;
    }
    unset($p);
    // Two connectors to two different expanders of one enclosure (dual-expander backplane, e.g. Supermicro EL2).
    $c['dual_expander'] = null;
    $exp = [];
    foreach ($c['connectors'] as $g) foreach ($g['expanders'] as $a) $exp[$a][$g['label']] = $g['label'];
    $addrs = array_keys($exp);
    for ($i = 0; $i < count($addrs) && !$c['dual_expander']; $i++) for ($j = $i + 1; $j < count($addrs); $j++) {
      [$a, $b] = [$addrs[$i], $addrs[$j]];
      if (!topo_same_enclosure($m, $k, $a, $b)) continue;
      $c['dual_expander'] = ['a' => $a, 'b' => $b, 'conn_a' => array_values($exp[$a]), 'conn_b' => array_values($exp[$b]),
                             'shelf' => $m['shelves'][$m['addr_shelf'][$a] ?? '']['label'] ?? ''];
      break;
    }
  }
  unset($c);
}

// Two expander addresses belong to one enclosure when their SES devices report the same enclosure, or (kernel view)
// when both are the same expander model and their addresses share everything but the last byte, as the two expanders
// of one dual-expander backplane do.
function topo_same_enclosure(array $m, array $k, string $a, string $b): bool {
  if (isset($m['addr_shelf'][$a], $m['addr_shelf'][$b]) && $m['addr_shelf'][$a] === $m['addr_shelf'][$b]) return true;
  $ea = $k['exp'][$k['exp_by_addr'][$a] ?? ''] ?? null; $eb = $k['exp'][$k['exp_by_addr'][$b] ?? ''] ?? null;
  return $ea && $eb && $ea['product'] !== '' && $ea['product'] === $eb['product'] && strlen($a) === 16 && substr($a, 0, 14) === substr($b, 0, 14);
}

// "Power Supply B" -> "PSU B", "Cooling Fan A 2" -> "Fan A 2"; other names unchanged.
function topo_short_name(string $n): string {
  return preg_replace(['/^Power Supply\b/i', '/^Cooling Fan\b/i'], ['PSU', 'Fan'], $n);
}

// A PSU's flags in words; '' when it has none worth a message.
function topo_psu_problem(array $x): string {
  $f = array_flip($x['flags'] ?? []);
  // Not installed, or a status of Unsupported/Unknown/Not available (e.g. the PSU element of an expander card that has
  // no PSU): its flag bits mean nothing, as for fans in topo_problems.
  if (in_array($x['level'], ['absent', 'unknown'], true)) return '';
  if (isset($f['ac_fail']) && (isset($f['off']) || isset($f['dc_fail']))) return 'no AC input (power cord unplugged or that feed is off)';
  $out = [];
  if (isset($f['ac_fail'])) $out[] = 'AC input failure';
  if (isset($f['dc_fail'])) $out[] = 'DC output failure';
  if (isset($f['overtmp_fail'])) $out[] = 'over-temperature failure';
  elseif (isset($f['temp_warn'])) $out[] = 'over-temperature warning';
  foreach (['dc_over_voltage' => 'DC over-voltage', 'dc_under_voltage' => 'DC under-voltage', 'dc_over_current' => 'DC over-current'] as $k => $t)
    if (isset($f[$k]) && !isset($f['dc_fail'])) $out[] = $t;
  if (isset($f['fail']) && !$out) $out[] = 'failed';
  if (isset($f['off']) && !$out) $out[] = 'turned off';
  if (!$out && in_array($x['level'], ['warn', 'crit'], true)) $out[] = "status {$x['status']}";
  return implode(', ', $out);
}

function topo_problems(array &$m): void {
  $p = &$m['problems'];
  // $key names what the problem is about (stable across collections); $value is what it says about it now. An
  // acknowledgement covers one key at one level and one value, so the problem comes back when the value changes.
  $add = function ($level, $text, $key = null, $value = null) use (&$p) {
    $p[] = ['level' => $level, 'text' => $text, 'key' => $key ?? $text, 'value' => (string)($value ?? $text)];
  };
  foreach ($m['errors'] as $e) $add('warn', "Collection: $e");
  if ($m['storcli'] === '' && $m['megaraid'])
    $add('info', 'A MegaRAID controller is present but storcli is not installed, so controller, port and per-drive detail is missing.');
  if (!$m['detail']) $add('info', 'Per-drive detail skipped because a disk is spun down (this page never wakes drives).');
  foreach ($m['controllers'] as $c) {
    $cid = "c{$c['id']}";
    // A status storcli did not report ('') is unknown, not a fault: one note per controller covers it and missing ports.
    if ($c['status'] !== '' && $c['status'] !== 'Optimal') $add('crit', "Controller $cid status: {$c['status']}", "ctrl|$cid|status", $c['status']);
    $missing = array_keys(array_filter(['no controller status' => $c['status'] === '', 'no port data' => !empty($c['no_phy_data']) || !empty($c['kernel_ports'])]));
    if ($missing) $add('info', "Controller $cid: storcli returned only partial data for it (" . implode(', ', $missing) . '), as it does with some OEM and IT-mode firmware'
      . (!empty($c['kernel_ports']) ? "; its ports are shown from the kernel's view." : '.'), "ctrl|$cid|partial", implode(',', $missing));
    if ($c['roc_temp'] !== null && $c['roc_temp'] >= 95) $add('warn', "Controller $cid chip at {$c['roc_temp']} C", "ctrl|$cid|temp", '');
    foreach ($c['ports'] as $pt) {
      if (!$pt['slow']) continue;
      $rates = implode('/', array_map('topo_rate_text', $pt['rates']));
      $exp = max($pt['expect'], max($pt['rates']));
      $what = $pt['remote_what'] ?? '';
      $why = $pt['remote_max'] > 0 && $what !== '' ? " (the $what supports " . topo_rate_text($pt['remote_max']) . ' Gb/s)' : '';
      $add('warn', "HBA $cid port {$pt['port']} has lanes below " . topo_rate_text($exp) . " Gb/s: $rates$why", "port|$cid|{$pt['port']}|rate", $rates);
    }
    foreach ($c['connectors'] ?? [] as $g) if ($g['partial'])
      $add('warn', "HBA $cid connector {$g['label']} ({$g['phy_text']}): only " . count($g['linked']) . ' of ' . count($g['phys'])
        . ' lanes linked to ' . implode(', ', $g['attached']) . ' - partially linked, check the cable and connector.', "conn|$cid|{$g['label']}", implode(',', array_keys($g['linked'])));
    if ($d = $c['dual_expander'] ?? null)
      $add('info', "HBA $cid: " . implode('+', $d['conn_a']) . ' and ' . implode('+', $d['conn_b']) . ' go to two different expanders of the same enclosure'
        . ($d['shelf'] ? " ({$d['shelf']})" : '') . '. SATA drives connect to the primary expander only, so for them the second cable adds no bandwidth; '
        . 'it gives SAS drives a second path (redundancy).', "dualexp|$cid", "{$d['a']}|{$d['b']}");
  }
  $ioms = [];
  foreach ($m['shelves'] as $s) {
    $sk = "shelf|{$s['key']}";
    // The enclosure element mirrors the shelf's fault LED: when another element explains it, the header says so instead.
    $others = [];
    foreach (['psu', 'fan', 'temp', 'volt', 'amp', 'conn', 'slot', 'esc'] as $k) foreach ($s['groups'][$k] as $x) $others[] = $x['level'];
    foreach ($s['ioms'] as $i) $others[] = $i['level'];
    $explained = (bool)array_intersect($others, ['warn', 'crit']);
    $m['shelves'][$s['key']]['fault_explained'] = $explained && $s['fault_led'] && in_array($s['level'], ['warn', 'crit'], true);
    if (($s['level'] === 'crit' || $s['level'] === 'warn') && !($explained && $s['fault_led']))
      $add($s['level'], "{$s['label']}: enclosure status {$s['status']}", "$sk|status", $s['status']);
    foreach ($s['ioms'] as $i) {
      $ioms[$i['fw']][] = "{$s['label']} {$i['name']}";
      if (in_array($i['level'], ['warn', 'crit'], true)) $add($i['level'], "{$s['label']} {$i['name']}: {$i['status']}", "$sk|iom|{$i['name']}", $i['status']);
    }
    // NetApp: each I/O module has its own SES device. (EMC LCCs share one, so this only applies with NetApp's IOM elements.)
    // With one cable (one I/O module cabled to this server) the other module's SES device is not reachable: normal.
    // It is a problem when the shelf is multipath, or when nothing tells how it is cabled.
    $singlePath = false;
    if ($s['groups']['iomx'] && count($s['sg']) < count($s['ioms'])) {
      $singlePath = ($s['paths'] ?? null) === 'single';
      if ($singlePath) $add('info', "{$s['label']}: single path: only one I/O module is cabled to this server (normal for a single-cable setup)", "$sk|ses", $s['paths_why']);
      else $add('warn', "{$s['label']}: only " . count($s['sg']) . ' of ' . count($s['ioms']) . ' I/O modules answer SES', "$sk|ses");
    }
    foreach (['psu' => 'PSU', 'fan' => 'Fan', 'temp' => 'Temp sensor', 'volt' => 'Voltage sensor', 'amp' => 'Current sensor', 'conn' => 'Connector', 'slot' => 'Bay'] as $k => $name)
      foreach ($s['groups'][$k] as $x) {
        $n = ($k === 'slot' ? $x['slot'] : $x['n'] + 1);
        $named = $k !== 'slot' && ($x['name'] ?? '') !== '';
        $ek = "$sk|$k|" . ($named ? $x['name'] : $n);
        $what = $named ? topo_short_name($x['name']) : "$name $n";
        if ($k === 'psu' && ($msg = topo_psu_problem($x))) {
          $add(in_array($x['level'], ['warn', 'crit'], true) ? $x['level'] : 'warn', "{$s['label']} $what: $msg", $ek, $x['status'] . '|' . implode(',', $x['flags']));
          if ($x['prdfail']) $add('warn', "{$s['label']} $what: predicted failure", "$ek|prdfail", '');
          continue;
        }
        // Fans and PSUs that are not installed or report Unknown (e.g. unused fan headers at 0 rpm) carry meaningless flags.
        $ignoreFlags = in_array($k, ['fan', 'psu'], true) && in_array($x['level'], ['absent', 'unknown'], true);
        $rpm = $k === 'fan' && isset($x['rpm']) && (int)$x['rpm'] === 0 ? ' at 0 rpm' : '';
        $val = $x['status'] . '|' . implode(',', $x['flags'] ?? []) . ($k === 'fan' ? '|' . ((int)($x['rpm'] ?? 0) === 0 ? 'stopped' : 'turning') : '');
        if (in_array($x['level'], ['warn', 'crit'], true)) $add($x['level'], "{$s['label']} $what: {$x['status']}" . (!empty($x['flags']) ? ' (' . implode(', ', $x['flags']) . ')' : '') . $rpm, $ek, $val);
        elseif (!empty($x['flags']) && !$ignoreFlags) $add('warn', "{$s['label']} $what: " . implode(', ', $x['flags']) . $rpm, $ek, $val);
        if (!empty($x['fault_sensed'])) $add('warn', "{$s['label']} bay {$x['slot']}: the shelf reports a fault (check the drive and, for SATA drives, the interposer)", "$ek|fault-sensed", '');
        elseif (!empty($x['fault_reqstd'])) $add('info', "{$s['label']} bay {$x['slot']}: fault LED turned on by host software (not a shelf-detected fault). Clear with: {$x['clear_cmd']}", "$ek|fault-reqstd", $x['clear_cmd']);
        if ($x['prdfail']) $add('warn', "{$s['label']} $what: predicted failure", "$ek|prdfail", '');
      }
    // One cable to a shelf (or an expander card) is the usual setup, so it is a note. (The level is part of a problem's
    // id, so acknowledgements of the earlier warning do not carry over to it.)
    if (!$s['multipath'] && $s['eid'] !== null && !$singlePath)
      $add('info', "{$s['label']}: single path to the controller (one cable; normal unless this enclosure has a second module you meant to cable)", "$sk|path", '');
  }
  if (count($ioms) > 1) {
    $parts = [];
    foreach ($ioms as $fw => $who) $parts[] = "$fw on " . implode(', ', $who);
    $add('warn', 'I/O module firmware differs: ' . implode('; ', $parts), 'fw|iom');
  }
  foreach ($m['cables'] as $c) if (array_intersect(['warn', 'crit'], $c['levels'])) $add('warn', "Cable {$c['local']} - {$c['remote']}: {$c['status']}", "cable|{$c['local']}|{$c['remote']}", $c['status']);
  foreach ($m['enclosures'] as $e) if (in_array($e['level'], ['info', 'warn', 'crit'], true))
    $add($e['level'], ($e['usb'] ? 'USB enclosure' : 'Enclosure') . " {$e['name']} ({$e['sg']}): status {$e['status_text']}", "encl|{$e['name']}|{$e['hctl']}", $e['status_text']);

  $slow = []; $other = 0; $otherDrives = 0;
  foreach ($m['drives'] as $d) {
    $who = $d['unraid'] ?: ($d['dev'] ?: ($d['eid'] !== null ? "e{$d['eid']}/s{$d['slot']}" : "s{$d['slot']}"));
    $dk = 'drive|' . ($d['serial'] ?: $who);
    if ($d['state'] && !in_array($d['state'], ['Onln', 'JBOD', 'UGood'], true)) $add('crit', "Drive $who state {$d['state']}", "$dk|state", $d['state']);
    if ($d['smart_alert']) $add('crit', "Drive $who: SMART alert", "$dk|smart", '');
    if ((int)$d['pred_fail'] > 0) $add('crit', "Drive $who: predictive failure count {$d['pred_fail']}", "$dk|pred", $d['pred_fail']);
    if ((int)$d['media_err'] > 0) $add('warn', "Drive $who: {$d['media_err']} media errors", "$dk|media", $d['media_err']);
    if ((int)$d['other_err'] > 0) { $other += (int)$d['other_err']; $otherDrives++; }
    if ($d['multipath'] && count(array_filter($d['paths'], fn($x) => $x['status'] === 'Active')) < 2) $add('warn', "Drive $who: only one path active", "$dk|paths", '');
    if ($d['max_rate'] && $d['rate'] && $d['rate'] < $d['max_rate']) $slow[] = $who;
  }
  sort($slow);
  if ($slow) $add('info', count($slow) . ' drives link below their maximum rate (' . count($m['drives']) . ' total), e.g. 6 Gb/s on 12 Gb/s drives.', 'drives|slow', implode(',', $slow));
  if ($otherDrives) $add('info', "$otherDrives drives have 'other' errors ($other in total) - usually link resets; watch for growth.", 'drives|other', $other);

  foreach ($m['other_ses'] as $o) {
    foreach (['temp' => 'Temp', 'fan' => 'Fan', 'psu' => 'PSU'] as $k => $name)
      foreach ($o['groups'][$k] as $x) if (in_array($x['level'], ['warn', 'crit'], true))
        $add($x['level'], "{$o['vendor']} {$o['product']} ({$o['sg']}) $name " . ($x['desc'] ?: $x['n'] + 1) . ": {$x['status']}" .
          (isset($x['value']) && $x['value'] !== null && $k === 'temp' ? " at {$x['value']} C" : ''), "ses|{$o['vendor']} {$o['product']}|$k|{$x['n']}", $x['status']);
  }
  $order = ['crit' => 0, 'warn' => 1, 'info' => 2];
  usort($p, fn($a, $b) => $order[$a['level']] <=> $order[$b['level']]);
  $m['level'] = topo_worst(array_column($p, 'level'));
}

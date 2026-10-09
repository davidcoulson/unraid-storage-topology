<?PHP
/* Storage topology model for StorageTopology.page.
 * Reads what scripts/collect.sh left in the cache folder (storcli JSON, sg_ses --join JSON, lsscsi, lsblk,
 * the kernel's enclosure and SAS sysfs, disks.ini) and returns one array: controllers with ports, shelves with I/O modules, power, cooling,
 * sensors, cables and bays, drives mapped to Unraid disk names, and a list of problems. No commands run here.
 *
 * NetApp DS424 shelves put useful data in the SES element descriptors (TP=..;SN=..;FW=..;). Other enclosures
 * still get status and sensors, just fewer labels.
 */

const TOPO_CACHE = '/var/local/storage-topology/current';

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

function topo_gbps($s): float { return preg_match('/([\d.]+)\s*Gb/i', (string)$s, $m) ? (float)$m[1] : 0.0; }

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

function topo_load(string $dir = TOPO_CACHE): array {
  $m = ['collected' => (int)@file_get_contents("$dir/done"), 'timings' => [], 'errors' => [], 'controllers' => [], 'sg_by_hctl' => [],
        'shelves' => [], 'other_ses' => [], 'drives' => [], 'cables' => [], 'problems' => [], 'detail' => true];
  if (!$m['collected']) { $m['errors'][] = 'No data collected yet.'; return $m; }
  foreach (@file("$dir/timings", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$name, $rc, $ms] = array_pad(explode('|', $l), 3, '');
    $m['timings'][$name] = ['rc' => (int)$rc, 'ms' => (int)$ms];
    if ((int)$rc !== 0) {
      $err = trim((string)@file_get_contents("$dir/$name.err"));
      $m['errors'][] = "$name: " . ((int)$rc === 124 ? 'timed out' : "exit $rc") . ($err ? " - $err" : '');
    }
  }
  if (is_file("$dir/drives.skipped")) $m['detail'] = false;
  $m['storcli'] = trim((string)@file_get_contents("$dir/storcli.path"));
  $m['megaraid'] = is_file("$dir/megaraid_sas");

  $names = topo_unraid_names($dir);          // serial => [name, type, device]
  $labels = [];                              // SAS address => human label
  topo_controllers($dir, $m, $labels);
  topo_drives($dir, $m, $names);
  topo_sysfs_hba($dir, $m, $labels);
  topo_ses($dir, $m, $labels);
  topo_sysfs_drives($dir, $m, $names);
  topo_shelf_drives($m);
  topo_links($m, $labels);
  topo_problems($m);
  return $m;
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
  foreach ($out as $serial => &$o) { $o['model'] = trim($blk[$o['device']]['model'] ?? ''); $o['size'] = $blk[$o['device']]['size'] ?? ''; $o['serial'] = $serial; }
  unset($o);
  return $out;
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
    $ports = [];
    foreach ($physBy[$id] ?? [] as $p) {
      if (empty($p['Port_valid'])) continue;
      $pn = (int)$p['Port'];
      $ports[$pn] ??= ['port' => $pn, 'phys' => [], 'rates' => [], 'attached' => topo_addr($p['SAS_Addr'] ?? ''),
                       'type' => trim($p['Device_Type'] ?? '')];
      $ports[$pn]['phys'][] = (int)$p['PhyNo'];
      $ports[$pn]['rates'][] = topo_gbps($p['Link_Speed'] ?? '');
    }
    ksort($ports);
    $unused = 0;
    foreach ($physBy[$id] ?? [] as $p) if (empty($p['Port_valid'])) $unused++;
    // The HBA's SAS address is its base; port n is reported on cables as base + n.
    if ($sas !== '') foreach ($ports as $pn => $_) {
      $addr = strtoupper(str_pad(dechex(hexdec(substr($sas, -4)) + $pn), 4, '0', STR_PAD_LEFT));
      $labels[substr($sas, 0, -4) . $addr] = "HBA c$id port $pn";
    }
    $m['controllers'][] = [
      'id' => $id, 'model' => $b['Model'] ?? '?', 'serial' => $b['Serial Number'] ?? '', 'sas' => $sas,
      'pci' => $b['PCI Address'] ?? '', 'fw_package' => $v['Firmware Package Build'] ?? '', 'fw' => $v['Firmware Version'] ?? '',
      'bios' => $v['Bios Version'] ?? '', 'driver' => trim(($v['Driver Name'] ?? '') . ' ' . ($v['Driver Version'] ?? '')),
      'status' => $s['Controller Status'] ?? '?', 'personality' => trim($s['Current Personality'] ?? ''),
      'roc_temp' => $hw['ROC temperature(Degree Celsius)'] ?? null, 'memory' => $hw['On Board Memory Size'] ?? '',
      'pending_fw' => $r['Pending Images in Flash'] ?? null, 'cli' => $c['Command Status']['CLI Version'] ?? '',
      'ports' => array_values($ports), 'unused_phys' => $unused,
      'encl' => [],
    ];
  }
  $encl = topo_json("$dir/encl.json");
  foreach ($encl['Controllers'] ?? [] as $c) {
    $id = $c['Command Status']['Controller'] ?? 0;
    foreach ($c['Response Data'] ?? [] as $key => $e) {
      if (!preg_match('#/e(\d+)$#', $key, $mm)) continue;
      $info = $e['Information'] ?? [];
      $wwn = topo_addr($info['EnclLogicalID'] ?? '');
      foreach ($m['controllers'] as &$ct) if ($ct['id'] == $id) $ct['encl'][$wwn] = [
        'eid' => (int)$mm[1], 'position' => $info['Position'] ?? '', 'status' => $info['Status'] ?? '',
        'serial' => $info['Enclosure Serial Number'] ?? '', 'rev' => $e['Inquiry Data']['Product Revision Level'] ?? '',
        'connector' => $info['Connector Name'] ?? '', 'multipath' => ($e['Properties'][0]['Port#'] ?? '') === 'Multipath'];
      unset($ct);
    }
  }
}

function topo_drives(string $dir, array &$m, array $names): void {
  $j = topo_json("$dir/drives.json");
  foreach ($j['Controllers'] ?? [] as $c) {
    $cid = $c['Command Status']['Controller'] ?? 0;
    $r = $c['Response Data'] ?? [];
    $rows = $r['Drive Information'] ?? null;     // brief form (drives spun down)
    if ($rows === null) {
      $rows = [];
      foreach ($r as $k => $v) if (preg_match('#^Drive (/c\d+/e\d+/s\d+)$#', $k, $mm) && isset($v[0])) {
        $row = $v[0];
        $row['_detail'] = $r["Drive {$mm[1]} - Detailed Information"] ?? [];
        $row['_path'] = $mm[1];
        $rows[] = $row;
      }
    }
    foreach ($rows as $row) {
      [$eid, $slot] = array_map('intval', explode(':', $row['EID:Slt'] ?? '0:0'));
      $path = $row['_path'] ?? "/c$cid/e$eid/s$slot";
      $d = $row['_detail'] ?? [];
      $st = $d["Drive $path State"] ?? []; $at = $d["Drive $path Device attributes"] ?? []; $po = $d["Drive $path Policies/Settings"] ?? [];
      $serial = trim($at['SN'] ?? '');
      $u = $names[$serial] ?? null;
      $paths = [];
      foreach ($po['Port Information'] ?? [] as $p) $paths[] = ['port' => $p['Port'], 'status' => $p['Status'] ?? '',
        'rate' => topo_gbps($p['Linkspeed'] ?? ''), 'sas' => topo_addr($p['SAS address'] ?? '')];
      $temp = preg_match('/(-?\d+)C/', $st['Drive Temperature'] ?? '', $tm) ? (int)$tm[1] : null;
      $m['drives']["$cid:$eid:$slot"] = [
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

function topo_ses(string $dir, array &$m, array &$labels): void {
  $inq = [];
  foreach (@file("$dir/lsscsi.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    if (!preg_match('#^\[([\d:]+)\]\s+enclosu\s+(.{8})\s(.{16})\s(\S+)\s.*?(/dev/sg\d+)\s*$#', $l, $mm)) continue;
    $inq[basename($mm[5])] = ['hctl' => $mm[1], 'vendor' => trim($mm[2]), 'product' => trim($mm[3]), 'rev' => trim($mm[4])];
    $m['sg_by_hctl'][$mm[1]] = basename($mm[5]);
  }
  $eidByWwn = [];
  foreach ($m['controllers'] as $c) foreach ($c['encl'] as $wwn => $e) $eidByWwn[$wwn] = ['ctrl' => $c['id']] + $e;

  foreach ($inq as $sg => $q) {
    $j = topo_json("$dir/ses_$sg.json");
    $els = $j['join_of_diagnostic_pages']['element_list'] ?? null;
    if ($els === null) {
      $raw = (string)@file_get_contents("$dir/ses_$sg.json") . (string)@file_get_contents("$dir/ses_$sg.json.err");
      $m['errors'][] = stripos($raw, 'json') !== false && stripos($raw, 'unrecognized') !== false
        ? "$sg: sg_ses is too old for --json (sg3_utils 1.48 or newer needed)" : "$sg: no SES data";
      continue;
    }
    $g = ['psu' => [], 'fan' => [], 'temp' => [], 'volt' => [], 'amp' => [], 'esc' => [], 'conn' => [], 'slot' => [],
          'encl' => [], 'iomx' => []];
    foreach ($els as $e) {
      if (empty($e['individual'])) continue;
      $sd = $e['status_descriptor'] ?? [];
      $status = $sd['status']['meaning'] ?? '';
      $x = ['n' => $e['element_number'], 'status' => $status, 'level' => topo_ses_level($status),
            'desc' => trim($e['descriptor'] ?? ''), 'kv' => topo_kv($e['descriptor'] ?? ''),
            'prdfail' => !empty($sd['prdfail'])];
      switch ($e['element_type']['i'] ?? -1) {
        case 1: case 23:
          $ae = $e['additional_element_status_descriptor'] ?? [];
          $x['slot'] = $ae['device_slot_number'] ?? $sd['slot_address'] ?? $x['n'];
          $x['fault'] = !empty($sd['fault_sensed']) || !empty($sd['fault_reqstd']);
          $x['ident'] = !empty($sd['ident']);
          $x['phys'] = array_map(fn($p) => topo_addr($p['sas_address'] ?? ''), $ae['phy_descriptor_list'] ?? []);
          $g['slot'][] = $x; break;
        case 2:
          $x['flags'] = array_keys(array_filter(array_intersect_key($sd, array_flip(['dc_over_voltage', 'dc_under_voltage',
            'dc_over_current', 'fail', 'off', 'overtmp_fail', 'temp_warn', 'ac_fail', 'dc_fail']))));
          $g['psu'][] = $x; break;
        case 3:
          $x['rpm'] = $sd['calculated_fan_speed'] ?? null; $x['code'] = $sd['actual_fan_code']['meaning'] ?? '';
          $x['flags'] = !empty($sd['fail']) ? ['fail'] : [];
          $g['fan'][] = $x; break;
        case 4:
          $x['value'] = preg_match('/(-?\d+)\s*C/', $sd['temperature']['meaning'] ?? '', $tm) ? (int)$tm[1] : null;
          $x['flags'] = array_keys(array_filter(array_intersect_key($sd, array_flip(['ot_failure', 'ot_warning', 'ut_failure', 'ut_warning', 'fail']))));
          $g['temp'][] = $x; break;
        case 7:  $g['esc'][] = $x; break;
        case 14: $g['encl'][] = $x; break;
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
    $enc = $g['encl'][0]['kv'] ?? [];
    $wwn = topo_addr($enc['WWN'] ?? '');
    $known = $wwn !== '' && isset($eidByWwn[$wwn]);
    $sasSlots = array_filter($g['slot'], fn($x) => array_filter($x['phys'], fn($a) => trim($a, '0') !== ''));
    if (!$known && !$sasSlots) {
      // Not a SAS drive shelf (e.g. the HighPoint NVMe card's SES device).
      $m['other_ses'][$sg] = ['sg' => $sg] + $q + ['groups' => $g];
      continue;
    }
    $key = $wwn ?: $sg;
    if (isset($m['shelves'][$key])) { $m['shelves'][$key]['sg'][] = $sg; continue; }   // same shelf seen through the other IOM
    $ioms = [];
    foreach ($g['esc'] as $i => $x) {
      $sa = topo_addr($g['iomx'][$i]['kv']['SA'] ?? '');
      $ioms[] = ['name' => 'IOM ' . chr(65 + $i), 'status' => $x['status'], 'level' => $x['level'], 'fw' => $x['kv']['FW'] ?? '',
                 'serial' => $x['kv']['SN'] ?? '', 'pn' => $x['kv']['PN'] ?? '', 'sas' => $sa];
    }
    $shelfId = ltrim($enc['ID'] ?? '', '0') ?: '?';
    $label = 'Shelf ' . ($enc['ID'] ?? $sg);
    foreach ($ioms as $iom) if ($iom['sas']) $labels[$iom['sas']] = "$label {$iom['name']}";
    $m['shelves'][$key] = [
      'key' => $key, 'label' => $label, 'shelf_id' => $shelfId, 'sg' => [$sg], 'vendor' => $q['vendor'], 'product' => $q['product'],
      'rev' => $q['rev'], 'wwn' => $wwn, 'serial' => $enc['SN'] ?? ($eidByWwn[$wwn]['serial'] ?? ''), 'pn' => $enc['PN'] ?? '',
      'status' => $g['encl'][0]['status'] ?? '', 'level' => $g['encl'][0]['level'] ?? 'unknown',
      'eid' => $eidByWwn[$wwn]['eid'] ?? null, 'ctrl' => $eidByWwn[$wwn]['ctrl'] ?? null,
      'multipath' => $eidByWwn[$wwn]['multipath'] ?? false,
      'ioms' => $ioms, 'groups' => $g,
    ];
  }
}

// Without storcli (e.g. an LSI HBA in IT mode on mpt3sas), describe each SAS HBA from the kernel's sas_phy data.
function topo_sysfs_hba(string $dir, array &$m, array &$labels): void {
  if ($m['controllers']) return;
  $hosts = [];
  foreach (@file("$dir/sas_hosts.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$h, $driver, $board, $fw] = array_pad(explode('|', $l), 4, '');
    $hosts[$h] = ['driver' => trim($driver), 'board' => trim($board, " \t\""), 'fw' => trim($fw)];
  }
  $byHost = [];
  foreach (@file("$dir/sas_phys.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$phy, $neg, $max, $sas, $port, $att] = array_pad(explode('|', $l), 6, '');
    if (!preg_match('/^phy-(\d+):(\d+)$/', $phy, $mm)) continue;
    $byHost[$mm[1]][] = ['phy' => (int)$mm[2], 'rate' => topo_gbps(str_replace('Gbit', 'Gb', $neg)), 'max' => topo_gbps(str_replace('Gbit', 'Gb', $max)),
                         'neg' => trim($neg), 'sas' => topo_addr($sas), 'port' => $port, 'att' => $att];
  }
  foreach ($byHost as $h => $phys) {
    $hi = $hosts["host$h"] ?? [];
    if (($hi['driver'] ?? '') === 'megaraid_sas') continue;   // megaraid hides its SAS layer; storcli is needed there
    $ports = []; $unused = 0;
    foreach ($phys as $p) {
      if ($p['port'] === '') { $unused++; continue; }
      [$attName, $attAddr] = array_pad(explode('=', $p['att'], 2), 2, '');
      // A port attached to its own address is a virtual management endpoint (e.g. a PCIe switch card's SES), not a cable.
      if (topo_addr($attAddr) === $p['sas']) continue;
      $ports[$p['port']] ??= ['port' => count($ports), 'phys' => [], 'rates' => [], 'attached' => topo_addr($attAddr),
                              'type' => str_starts_with($attName, 'expander') ? 'Expander' : ($attName ? 'End device' : '')];
      $ports[$p['port']]['phys'][] = $p['phy'];
      $ports[$p['port']]['rates'][] = $p['rate'];
    }
    if (!$ports) continue;
    $sas = $phys[0]['sas'] ?? '';
    $m['controllers'][] = [
      'id' => (int)$h, 'model' => $hi['board'] ?: ($hi['driver'] ?: 'SAS HBA') . " (host$h)", 'serial' => '', 'sas' => $sas, 'pci' => '',
      'fw_package' => '', 'fw' => $hi['fw'] ?? '', 'bios' => '', 'driver' => $hi['driver'] ?? '', 'status' => 'Optimal',
      'personality' => 'HBA (kernel view, storcli not installed)', 'roc_temp' => null, 'memory' => '', 'pending_fw' => null, 'cli' => '',
      'ports' => array_values($ports), 'unused_phys' => $unused, 'encl' => [], 'sysfs' => true,
    ];
  }
}

// Bays of shelves storcli does not know: the kernel's enclosure driver links each bay to its disk.
function topo_sysfs_drives(string $dir, array &$m, array $names): void {
  $byDev = [];
  foreach ($names as $n) $byDev[$n['device']] = $n;
  foreach (@file("$dir/enclosure_sysfs.txt", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    [$hctl, $id, $comp, $slot, $status, $fault, $locate, $blk] = array_pad(explode('|', $l), 8, '');
    // The kernel names an enclosure by its SES device's SCSI address; find the shelf that SES device belongs to.
    $sg = $m['sg_by_hctl'][$hctl] ?? '';
    $key = null;
    foreach ($m['shelves'] as $k => $s) if (in_array($sg, $s['sg'], true)) $key = $k;
    if ($key === null || $m['shelves'][$key]['eid'] !== null || $blk === '') continue;
    $wwn = $key;
    $n = $byDev[$blk] ?? ['name' => '', 'type' => '', 'device' => $blk, 'spundown' => false, 'temp' => null, 'errors' => null, 'model' => '', 'size' => '', 'serial' => ''];
    $slotNo = is_numeric($slot) ? (int)$slot : (is_numeric($comp) ? (int)$comp : $comp);
    $m['drives']["sysfs:$wwn:$slotNo"] = [
      'ctrl' => null, 'eid' => null, 'shelf' => $wwn, 'slot' => $slotNo, 'did' => null, 'state' => '', 'type' => '', 'intf' => '', 'med' => '',
      'size' => $n['size'], 'model' => $n['model'], 'vendor' => '', 'serial' => $n['serial'], 'fw' => '', 'wwn' => '',
      'max_rate' => 0.0, 'rate' => 0.0, 'temp' => $n['temp'], 'media_err' => null, 'other_err' => null, 'pred_fail' => null,
      'smart_alert' => false, 'paths' => [], 'multipath' => false, 'unraid_errors' => $n['errors'],
      'unraid' => $n['name'], 'unraid_type' => $n['type'], 'dev' => $blk, 'spundown' => $n['spundown'],
    ];
  }
}

// Attach drives to their shelf: storcli drives by controller + enclosure ID, kernel-view drives by enclosure WWN.
function topo_shelf_drives(array &$m): void {
  foreach ($m['shelves'] as $key => &$s) {
    $s['drives'] = [];
    foreach ($m['drives'] as $d) {
      $mine = $s['eid'] !== null ? ($d['eid'] === $s['eid'] && $d['ctrl'] === $s['ctrl']) : (($d['shelf'] ?? null) === $key);
      if ($mine) $s['drives'][$d['slot']] = $d;
    }
    ksort($s['drives']);
  }
  unset($s);
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
  foreach ($m['controllers'] as &$c) foreach ($c['ports'] as &$p) $p['attached_label'] = $labels[$p['attached']] ?? $p['attached'];
  unset($c, $p);
  usort($m['cables'], fn($a, $b) => [!$a['to_hba'], $a['local']] <=> [!$b['to_hba'], $b['local']]);
}

function topo_problems(array &$m): void {
  $p = &$m['problems'];
  $add = function ($level, $text) use (&$p) { $p[] = ['level' => $level, 'text' => $text]; };
  foreach ($m['errors'] as $e) $add('warn', "Collection: $e");
  if ($m['storcli'] === '' && $m['megaraid'])
    $add('info', 'A MegaRAID controller is present but storcli is not installed, so controller, port and per-drive detail is missing.');
  if (!$m['detail']) $add('info', 'Per-drive detail skipped because a disk is spun down (this page never wakes drives).');
  foreach ($m['controllers'] as $c) {
    if (!in_array($c['status'], ['Optimal'], true)) $add('crit', "Controller c{$c['id']} status: {$c['status']}");
    if ($c['roc_temp'] !== null && $c['roc_temp'] >= 95) $add('warn', "Controller c{$c['id']} chip at {$c['roc_temp']} C");
    foreach ($c['ports'] as $pt) {
      if (count($pt['phys']) < 4 && stripos($pt['type'], 'expander') !== false) $add('warn', "HBA c{$c['id']} port {$pt['port']} is x" . count($pt['phys']) . ' (expected x4)');
      if ($pt['rates'] && min($pt['rates']) < 12) $add('warn', "HBA c{$c['id']} port {$pt['port']} has lanes below 12 Gb/s: " . implode('/', $pt['rates']));
    }
  }
  $ioms = [];
  foreach ($m['shelves'] as $s) {
    if ($s['level'] === 'crit' || $s['level'] === 'warn') $add($s['level'], "{$s['label']}: enclosure status {$s['status']}");
    foreach ($s['ioms'] as $i) {
      $ioms[$i['fw']][] = "{$s['label']} {$i['name']}";
      if (in_array($i['level'], ['warn', 'crit'], true)) $add($i['level'], "{$s['label']} {$i['name']}: {$i['status']}");
    }
    if (count($s['sg']) < count($s['ioms'])) $add('warn', "{$s['label']}: only " . count($s['sg']) . ' of ' . count($s['ioms']) . ' I/O modules answer SES');
    foreach (['psu' => 'PSU', 'fan' => 'Fan', 'temp' => 'Temp sensor', 'volt' => 'Voltage sensor', 'amp' => 'Current sensor', 'conn' => 'Connector', 'slot' => 'Bay'] as $k => $name)
      foreach ($s['groups'][$k] as $x) {
        $n = ($k === 'slot' ? $x['slot'] : $x['n'] + 1);
        if (in_array($x['level'], ['warn', 'crit'], true)) $add($x['level'], "{$s['label']} $name $n: {$x['status']}" . (!empty($x['flags']) ? ' (' . implode(', ', $x['flags']) . ')' : ''));
        elseif (!empty($x['flags'])) $add('warn', "{$s['label']} $name $n: " . implode(', ', $x['flags']));
        if (!empty($x['fault'])) $add('warn', "{$s['label']} bay {$x['slot']}: fault LED");
        if ($x['prdfail']) $add('warn', "{$s['label']} $name $n: predicted failure");
      }
    if (!$s['multipath'] && $s['eid'] !== null) $add('warn', "{$s['label']}: single path to the controller");
  }
  if (count($ioms) > 1) {
    $parts = [];
    foreach ($ioms as $fw => $who) $parts[] = "$fw on " . implode(', ', $who);
    $add('warn', 'I/O module firmware differs: ' . implode('; ', $parts));
  }
  foreach ($m['cables'] as $c) if (array_intersect(['warn', 'crit'], $c['levels'])) $add('warn', "Cable {$c['local']} - {$c['remote']}: {$c['status']}");

  $slow = []; $other = 0; $otherDrives = 0;
  foreach ($m['drives'] as $d) {
    $who = $d['unraid'] ?: ($d['dev'] ?: "e{$d['eid']}/s{$d['slot']}");
    if ($d['state'] && !in_array($d['state'], ['Onln', 'JBOD', 'UGood'], true)) $add('crit', "Drive $who state {$d['state']}");
    if ($d['smart_alert']) $add('crit', "Drive $who: SMART alert");
    if ((int)$d['pred_fail'] > 0) $add('crit', "Drive $who: predictive failure count {$d['pred_fail']}");
    if ((int)$d['media_err'] > 0) $add('warn', "Drive $who: {$d['media_err']} media errors");
    if ((int)$d['other_err'] > 0) { $other += (int)$d['other_err']; $otherDrives++; }
    if ($d['multipath'] && count(array_filter($d['paths'], fn($x) => $x['status'] === 'Active')) < 2) $add('warn', "Drive $who: only one path active");
    if ($d['max_rate'] && $d['rate'] && $d['rate'] < $d['max_rate']) $slow[] = $who;
  }
  if ($slow) $add('info', count($slow) . ' drives link below their maximum rate (' . count($m['drives']) . ' total), e.g. 6 Gb/s on 12 Gb/s drives.');
  if ($otherDrives) $add('info', "$otherDrives drives have 'other' errors ($other in total) - usually link resets; watch for growth.");

  foreach ($m['other_ses'] as $o) {
    foreach (['temp' => 'Temp', 'fan' => 'Fan', 'psu' => 'PSU'] as $k => $name)
      foreach ($o['groups'][$k] as $x) if (in_array($x['level'], ['warn', 'crit'], true))
        $add($x['level'], "{$o['vendor']} {$o['product']} ({$o['sg']}) $name " . ($x['desc'] ?: $x['n'] + 1) . ": {$x['status']}" .
          (isset($x['value']) && $x['value'] !== null && $k === 'temp' ? " at {$x['value']} C" : ''));
  }
  $order = ['crit' => 0, 'warn' => 1, 'info' => 2];
  usort($p, fn($a, $b) => $order[$a['level']] <=> $order[$b['level']]);
  $m['level'] = topo_worst(array_column($p, 'level'));
}

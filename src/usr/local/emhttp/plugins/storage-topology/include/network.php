<?PHP
/* Network topology model for NetworkTopology.page.
 * Reads what scripts/collect-net.sh left in the cache folder (ethtool text output, lspci, mstflint query,
 * /proc/net/bonding, VLAN and bridge lists, hwmon, counters, lldpctl JSON) and returns one array: NIC cards with
 * their ports, transceiver modules, bonds, bridges/VLANs, LLDP neighbours, firmware, and a list of problems.
 * No commands run here.
 */

const NET_CACHE = '/var/local/storage-topology/net/current';

// Counters shown for every port that has them: key => [label, class]. Classes decide how growth is judged:
// link = link went down, err = corrupted frames, fec = corrected by FEC, drop = lost for lack of buffers, note = shown only.
const NET_COUNTERS = [
  'link_down_events_phy'  => ['Link down events (PHY)', 'link'],
  'sys.carrier_down_count' => ['Carrier down (kernel)', 'link'],
  'rx_crc_errors_phy'     => ['CRC errors', 'err'],
  'rx_symbol_err_phy'     => ['Symbol errors', 'err'],
  'rx_pcs_symbol_err_phy' => ['PCS symbol errors', 'err'],
  'rx_crc_errors'         => ['CRC errors', 'err'],
  'rx_crc_errors.nic'     => ['CRC errors', 'err'],
  'sys.rx_crc_errors'     => ['Rx CRC errors', 'err'],
  'sys.rx_errors'         => ['Rx errors', 'err'],
  'sys.tx_errors'         => ['Tx errors', 'err'],
  'rx_corrected_bits_phy' => ['FEC corrected bits', 'fec'],
  'rx_discards_phy'       => ['Rx discards (port buffer)', 'drop'],
  'rx_out_of_buffer'      => ['Rx out of buffer (host)', 'drop'],
  'sys.rx_missed_errors'  => ['Rx missed', 'drop'],
  'tx_discards_phy'       => ['Tx discards', 'drop'],
  'sys.rx_dropped'        => ['Rx dropped (kernel)', 'note'],
  'sys.tx_dropped'        => ['Tx dropped (kernel)', 'note'],
];
// The kernel's copy of a driver counter is hidden when the driver's own one is there.
const NET_DUPLICATES = ['sys.rx_crc_errors' => ['rx_crc_errors_phy', 'rx_crc_errors', 'rx_crc_errors.nic'], 'sys.rx_missed_errors' => ['rx_out_of_buffer']];

// LACP port state bits (IEEE 802.1AX).
const NET_LACP_BITS = [1 => 'active', 2 => 'short timeout', 4 => 'aggregatable', 8 => 'in sync', 16 => 'collecting',
                       32 => 'distributing', 64 => 'defaulted', 128 => 'expired'];

function net_worst(array $levels): string {
  foreach (['crit', 'warn', 'info', 'ok'] as $l) if (in_array($l, $levels, true)) return $l;
  return 'ok';
}

function net_lines(string $file): array { return @file($file, FILE_IGNORE_NEW_LINES) ?: []; }

function net_num($s): ?float { return preg_match('/-?\d+(?:\.\d+)?/', (string)$s, $mm) ? (float)$mm[0] : null; }

// "16.0 GT/s PCIe", "Speed 16GT/s" -> 16.0
function net_gts($s): ?float { return preg_match('/([\d.]+)\s*GT\/s/', (string)$s, $mm) ? (float)$mm[1] : null; }

// Usable PCIe bandwidth in Gb/s: 8b/10b encoding up to 5 GT/s, 128b/130b from 8 GT/s.
function net_pcie_gbps(?float $gt, ?int $width): ?float {
  if (!$gt || !$width) return null;
  return $gt * $width * ($gt >= 8 ? 128 / 130 : 0.8);
}

function net_speed_text($mbps): string {
  if (!$mbps) return '-';
  return $mbps >= 1000 ? rtrim(rtrim(number_format($mbps / 1000, 1), '0'), '.') . 'G' : "{$mbps}M";
}

function net_duration(?int $s): string {
  if ($s === null) return '';
  if ($s < 120) return "$s s";
  if ($s < 7200) return round($s / 60) . ' min';
  if ($s < 172800) return round($s / 3600, 1) . ' h';
  return round($s / 86400, 1) . ' days';
}

// ethtool's "Key: value" output, with indented continuation lines appended to the previous key.
function net_ethtool_kv(string $text): array {
  $out = []; $key = null;
  foreach (explode("\n", $text) as $l) {
    if (preg_match('/^\s*([A-Za-z][^:]*?):\s*(.*)$/', $l, $mm) && !preg_match('/^\s+\d/', $l)) {
      $key = trim($mm[1]);
      $out[$key] = trim($mm[2]);
    } elseif ($key !== null && trim($l) !== '') {
      $out[$key] .= ' ' . trim($l);
    }
  }
  return $out;
}

// ethtool -m: "Key   : value" lines. Keys repeat (e.g. "Extended identifier description"), so keep a list.
function net_module(string $text): ?array {
  $rows = [];
  foreach (explode("\n", $text) as $l) if (preg_match('/^\s*(.+?)\s*:\s(.*)$/', $l, $mm)) $rows[] = [trim($mm[1]), trim($mm[2])];
  if (!$rows) return null;
  $first = function ($k) use ($rows) { foreach ($rows as [$a, $b]) if ($a === $k) return $b; return ''; };
  $paren = fn($v) => preg_match('/\(([^)]+)\)/', $v, $mm) ? $mm[1] : $v;
  $m = [
    'id' => $paren($first('Identifier')), 'connector' => $paren($first('Connector')),
    'vendor' => $first('Vendor name'), 'pn' => $first('Vendor PN'), 'rev' => $first('Vendor rev'), 'sn' => $first('Vendor SN'),
    'date' => $first('Date code'), 'wavelength' => $first('Laser wavelength'), 'types' => [], 'length' => '',
    'temp' => null, 'volt' => null, 'lanes' => [], 'thr' => [], 'flags' => [], 'los' => '', 'dom' => false,
  ];
  foreach ($rows as [$k, $v]) {
    if ($k === 'Transceiver type') $m['types'][] = $v;
    elseif (str_starts_with($k, 'Length (') && net_num($v)) $m['length'] .= ($m['length'] === '' ? '' : ', ') . trim(substr($k, 8, -1)) . ' ' . $v;
    elseif ($k === 'Module temperature') $m['temp'] = net_num($v);
    elseif ($k === 'Module voltage') $m['volt'] = net_num($v);
    elseif ($k === 'Rx loss of signal') $m['los'] = $v;
    elseif (preg_match('/^(Laser tx bias current|Laser bias current)(?: \(Channel (\d+)\))?$/', $k, $mm))
      $m['lanes'][(int)($mm[2] ?? 1) ?: 1]['bias'] = net_num($v);
    elseif (preg_match('/^(Transmit avg optical power|Laser output power)(?: \(Channel (\d+)\))?$/', $k, $mm))
      $m['lanes'][(int)($mm[2] ?? 1) ?: 1]['tx'] = net_power($v);
    elseif (preg_match('/^(Rcvr signal avg optical power|Receiver signal average optical power|Rx power)(?: \(Channel (\d+)\))?$/', $k, $mm))
      $m['lanes'][(int)($mm[2] ?? 1) ?: 1]['rx'] = net_power($v);
    elseif (preg_match('/^(.+?) (high|low) (alarm|warning) threshold$/', $k, $mm) && ($what = net_module_metric($mm[1])))
      $m['thr'][$what][$mm[2]][$mm[3]] = net_num($v);
    elseif (preg_match('/^(.+?) (high|low) (alarm|warning)(?: \(Chan(?:nel)? (\d+)\))?$/', $k, $mm) && strcasecmp($v, 'On') === 0
            && ($what = net_module_metric($mm[1])))
      $m['flags'][] = ['what' => $what, 'lane' => isset($mm[4]) ? (int)$mm[4] : 0, 'dir' => $mm[2], 'level' => $mm[3] === 'alarm' ? 'crit' : 'warn'];
  }
  ksort($m['lanes']);
  // Passive cables have no diagnostics; some report all-zero readings instead of none.
  $vals = [$m['temp'], $m['volt']];
  foreach ($m['lanes'] as $l) array_push($vals, $l['bias'] ?? null, $l['tx']['mw'] ?? null, $l['rx']['mw'] ?? null);
  $m['dom'] = (bool)array_filter($vals, fn($v) => $v !== null && $v != 0);
  $m['type'] = implode('; ', array_unique($m['types']));
  return $m;
}

// "1.3608 mW / 1.34 dBm" -> [mw, dbm]
function net_power(string $v): array {
  $mw = preg_match('/([\d.]+)\s*mW/', $v, $a) ? (float)$a[1] : null;
  $dbm = preg_match('/(-?[\d.]+|-inf)\s*dBm/', $v, $b) ? ($b[1] === '-inf' ? -40.0 : (float)$b[1]) : null;
  if ($dbm === null && $mw !== null) $dbm = $mw > 0 ? round(10 * log10($mw), 2) : -40.0;
  return ['mw' => $mw, 'dbm' => $dbm];
}

function net_module_metric(string $subject): ?string {
  return match (true) {
    str_contains($subject, 'bias') => 'bias',
    str_contains($subject, 'rx power'), str_contains($subject, 'input power') => 'rx',
    str_contains($subject, 'tx power'), str_contains($subject, 'output power') => 'tx',
    str_contains($subject, 'temperature') => 'temp',
    str_contains($subject, 'voltage') => 'volt',
    default => null,
  };
}

// Level of one module reading against the module's own thresholds (power thresholds are in mW).
function net_module_level(array $mod, string $what, ?float $v): string {
  $t = $mod['thr'][$what] ?? [];
  if ($v === null || !$t) return 'ok';
  if (isset($t['high']['alarm']) && $v > $t['high']['alarm']) return 'crit';
  if (isset($t['low']['alarm']) && $v < $t['low']['alarm']) return 'crit';
  if (isset($t['high']['warning']) && $v > $t['high']['warning']) return 'warn';
  if (isset($t['low']['warning']) && $v < $t['low']['warning']) return 'warn';
  return 'ok';
}

// Readings with their levels: [what => [lane => [value, level, flag]]], lane 0 = whole module.
function net_module_readings(array $mod): array {
  $r = [];
  if ($mod['temp'] !== null) $r['temp'][0] = ['v' => $mod['temp'], 'level' => net_module_level($mod, 'temp', $mod['temp'])];
  if ($mod['volt'] !== null) $r['volt'][0] = ['v' => $mod['volt'], 'level' => net_module_level($mod, 'volt', $mod['volt'])];
  foreach ($mod['lanes'] as $n => $l) {
    if (isset($l['bias'])) $r['bias'][$n] = ['v' => $l['bias'], 'level' => net_module_level($mod, 'bias', $l['bias'])];
    foreach (['tx', 'rx'] as $k) if (isset($l[$k])) $r[$k][$n] = ['v' => $l[$k]['mw'], 'dbm' => $l[$k]['dbm'], 'level' => net_module_level($mod, $k, $l[$k]['mw'])];
  }
  // The module's own alarm/warning flags count too (some modules flag without the host-visible value crossing).
  foreach ($mod['flags'] as $f) {
    $lane = $f['lane'];
    // A flag without a lane (0) belongs to the one reading of a single-lane metric; otherwise it keeps its own lane,
    // shown without a value when that lane has no reading, so it is never reported against another lane.
    if ($lane === 0 && !isset($r[$f['what']][0]) && count($r[$f['what']] ?? []) === 1) $lane = array_key_first($r[$f['what']]);
    $r[$f['what']][$lane] ??= ['v' => null, 'level' => 'ok'];
    $r[$f['what']][$lane]['level'] = net_worst([$r[$f['what']][$lane]['level'], $f['level']]);
    $r[$f['what']][$lane]['flag'] = "{$f['dir']} " . ($f['level'] === 'crit' ? 'alarm' : 'warning');
  }
  return $r;
}

// /proc/net/bonding/<bond>
function net_bond(string $text): array {
  $b = ['mode' => '', 'hash' => '', 'lacp_rate' => '', 'mii' => '', 'agg' => null, 'agg_ports' => null, 'partner_mac' => '',
        'actor_key' => '', 'partner_key' => '', 'min_links' => '', 'active_slave' => '', 'slaves' => []];
  $s = null; $sec = '';
  foreach (explode("\n", $text) as $l) {
    if (!preg_match('/^\s*([^:]+?):\s*(.*)$/', $l, $mm)) continue;
    [$k, $v] = [trim($mm[1]), trim($mm[2])];
    if ($k === 'Slave Interface') { $b['slaves'][$v] = ['name' => $v, 'mii' => '', 'speed' => '', 'duplex' => '', 'failures' => 0,
      'agg' => null, 'actor_churn' => '', 'partner_churn' => '', 'actor_churned' => 0, 'partner_churned' => 0, 'actor_state' => null,
      'partner_state' => null, 'partner_mac' => '', 'partner_port' => '', 'perm' => '']; $s = $v; $sec = 'slave'; continue; }
    if ($k === 'Active Aggregator Info') { $sec = 'agg'; continue; }
    if ($k === 'details actor lacp pdu') { $sec = 'actor'; continue; }
    if ($k === 'details partner lacp pdu') { $sec = 'partner'; continue; }
    if ($s === null) {
      match ($k) {
        'Bonding Mode' => $b['mode'] = $v, 'Transmit Hash Policy' => $b['hash'] = $v, 'LACP rate' => $b['lacp_rate'] = $v,
        'MII Status' => $b['mii'] = $v, 'Min links' => $b['min_links'] = $v, 'Currently Active Slave' => $b['active_slave'] = $v,
        'Aggregator ID' => $b['agg'] = (int)$v, 'Number of ports' => $b['agg_ports'] = (int)$v, 'Partner Mac Address' => $b['partner_mac'] = $v,
        'Actor Key' => $b['actor_key'] = $v, 'Partner Key' => $b['partner_key'] = $v,
        default => null,
      };
      continue;
    }
    $sl = &$b['slaves'][$s];
    if ($sec === 'actor') { if ($k === 'port state') $sl['actor_state'] = (int)$v; }
    elseif ($sec === 'partner') {
      if ($k === 'port state') $sl['partner_state'] = (int)$v;
      elseif ($k === 'system mac address') $sl['partner_mac'] = $v;
      elseif ($k === 'port number') $sl['partner_port'] = $v;
    } else match ($k) {
      'MII Status' => $sl['mii'] = $v, 'Speed' => $sl['speed'] = $v, 'Duplex' => $sl['duplex'] = $v,
      'Link Failure Count' => $sl['failures'] = (int)$v, 'Aggregator ID' => $sl['agg'] = (int)$v,
      'Actor Churn State' => $sl['actor_churn'] = $v, 'Partner Churn State' => $sl['partner_churn'] = $v,
      'Actor Churned Count' => $sl['actor_churned'] = (int)$v, 'Partner Churned Count' => $sl['partner_churned'] = (int)$v,
      'Permanent HW addr' => $sl['perm'] = $v,
      default => null,
    };
    unset($sl);
  }
  return $b;
}

function net_lacp_state(?int $s): string {
  if ($s === null) return '';
  $on = [];
  foreach (NET_LACP_BITS as $bit => $name) if ($s & $bit) $on[] = $name;
  return implode(', ', $on);
}

// lldpctl JSON: json0 wraps every value in a list; plain json does not, and keys interfaces/chassis by name.
function net_jget($x, string ...$path) {
  foreach ($path as $k) {
    while (is_array($x) && $x && array_is_list($x)) $x = $x[0];
    if (!is_array($x) || !array_key_exists($k, $x)) return null;
    $x = $x[$k];
  }
  while (is_array($x) && $x && array_is_list($x)) $x = $x[0];
  if (is_array($x) && array_key_exists('value', $x)) $x = $x['value'];
  return is_scalar($x) ? (string)$x : null;
}

function net_jlist($x): array {
  if ($x === null) return [];
  return is_array($x) && array_is_list($x) ? $x : [$x];
}

function net_lldp(?array $j): array {
  $out = [];
  $root = $j['lldp'] ?? null;
  if (is_array($root) && array_is_list($root)) $root = $root[0] ?? null;
  foreach (net_jlist($root['interface'] ?? null) as $e) {
    if (!is_array($e)) continue;
    $items = isset($e['name']) || isset($e['chassis']) ? [[(string)($e['name'] ?? ''), $e]] : array_map(null, array_keys($e), array_values($e));
    foreach ($items as [$ifname, $i]) {
      if (!is_array($i) || $ifname === '') continue;
      $ch = $i['chassis'] ?? [];
      if (is_array($ch) && array_is_list($ch)) $ch = $ch[0] ?? [];
      $chName = net_jget($ch, 'name');
      if ($chName === null && is_array($ch) && count($ch) === 1 && !isset($ch['id'])) { $chName = (string)array_key_first($ch); $ch = reset($ch); }
      $mgmt = [];
      foreach (net_jlist($ch['mgmt-ip'] ?? null) as $ip) $mgmt[] = is_array($ip) ? ($ip['value'] ?? '') : (string)$ip;
      $vlans = [];
      foreach (net_jlist($i['vlan'] ?? null) as $v) if (is_array($v))
        $vlans[] = ['id' => net_jget($v, 'vlan-id') ?? '', 'pvid' => !empty($v['pvid']), 'name' => $v['value'] ?? ''];
      $lag = net_jget($i, 'port', 'aggregation');
      foreach (net_jlist($i['unknown-tlvs'] ?? null) as $u) foreach (net_jlist($u['unknown-tlv'] ?? null) as $t) {
        // 802.1 Link Aggregation TLV (OUI 00-80-C2 subtype 7): status byte, then the aggregated port ID.
        if (is_array($t) && strtoupper(str_replace([',', '-', ':'], '', (string)($t['oui'] ?? ''))) === '0080C2' && (string)($t['subtype'] ?? '') === '7') {
          $bytes = array_map('hexdec', explode(',', (string)($t['value'] ?? '')));
          if (count($bytes) >= 5 && ($bytes[0] & 2)) $lag ??= (string)(($bytes[1] << 24) | ($bytes[2] << 16) | ($bytes[3] << 8) | $bytes[4]);
        }
      }
      $out[$ifname] = [
        'switch' => $chName ?? '', 'chassis_id' => net_jget($ch, 'id') ?? '', 'descr' => net_jget($ch, 'descr') ?? '',
        'mgmt' => array_values(array_filter($mgmt)), 'port_id' => net_jget($i, 'port', 'id') ?? '',
        'port_descr' => net_jget($i, 'port', 'descr') ?? '', 'mfs' => net_jget($i, 'port', 'mfs'),
        'vlans' => $vlans, 'lag' => $lag, 'age' => (string)($i['age'] ?? ''),
        'model' => trim((net_jget($i, 'lldp-med', 'inventory', 'manufacturer') ?? '') . ' ' . (net_jget($i, 'lldp-med', 'inventory', 'model') ?? '')),
      ];
    }
  }
  return $out;
}

function net_counters(string $file): array {
  $c = [];
  foreach (net_lines($file) as $l) {
    $p = explode('|', $l);
    if (count($p) === 3 && is_numeric($p[2])) $c[$p[0]][$p[1]] = (int)$p[2];
  }
  return $c;
}

function net_load(string $dir = NET_CACHE): array {
  $m = ['collected' => (int)@file_get_contents("$dir/done"), 'timings' => [], 'errors' => [], 'notes' => [], 'ports' => [],
        'cards' => [], 'bonds' => [], 'bridges' => [], 'uplinks' => [], 'lldp' => null, 'firmware' => [], 'problems' => [],
        'window' => null, 'level' => 'ok'];
  if (!$m['collected']) { $m['errors'][] = 'No data collected yet.'; net_problems($m); return $m; }
  foreach (net_lines("$dir/timings") as $l) {
    [$name, $rc, $ms] = array_pad(explode('|', $l), 3, '');
    $m['timings'][$name] = ['rc' => (int)$rc, 'ms' => (int)$ms];
    if ((int)$rc === 0) continue;
    $err = trim((string)@file_get_contents("$dir/$name.err"));
    if ((int)$rc === 124 || (int)$rc === 137) $m['errors'][] = "$name: timed out";
    elseif (preg_match('/^(lspci|drvinfo|ethtool)_/', $name)) $m['errors'][] = "$name: exit $rc" . ($err ? " - $err" : '');
    elseif (str_starts_with($name, 'stats_')) $m['notes'][] = "$name: exit $rc (no driver counters for this port)" . ($err ? " - $err" : '');
    elseif (preg_match('/^(mstflint_|lldp)/', $name)) $m['notes'][] = "$name: exit $rc" . ($err ? " - $err" : '');
    // fec_/module_/ring_/stats_ fail with "Operation not supported" on ports without that feature: not a problem.
  }

  $now = net_counters("$dir/counters.txt");
  $base = is_file("$dir/base/done") ? net_counters("$dir/base/counters.txt") : [];
  $baseAt = (int)@file_get_contents("$dir/base/done");
  if ($base && $baseAt) $m['window'] = $m['collected'] - $baseAt;
  $delta = function ($who, $key) use ($now, $base): ?int {
    if (!isset($base[$who][$key], $now[$who][$key])) return null;
    $d = $now[$who][$key] - $base[$who][$key];
    return $d >= 0 ? $d : null;               // went backwards: driver reloaded or counters reset
  };

  // Ports
  foreach (net_lines("$dir/ifaces.txt") as $l) {
    [$name, $mac, $oper, $carrier, $mtu, $speed, $duplex, $master, $cdown, $bdf, $driver, $wl] = array_pad(explode('|', $l), 12, '');
    if ($name === '') continue;
    $et = net_ethtool_kv((string)@file_get_contents("$dir/ethtool_$name"));
    $di = net_ethtool_kv((string)@file_get_contents("$dir/drvinfo_$name"));
    $max = 0;
    if (preg_match_all('/(\d+)base/', $et['Supported link modes'] ?? '', $mm)) $max = max(array_map('intval', $mm[1]));
    $spd = preg_match('/^(\d+)\s*Mb/', $et['Speed'] ?? '', $sm) ? (int)$sm[1] : ((int)$speed > 0 ? (int)$speed : null);
    $p = [
      'name' => $name, 'mac' => $mac, 'state' => $oper, 'up' => $oper === 'up', 'mtu' => (int)$mtu, 'speed' => $spd,
      'duplex' => strtolower(preg_replace('/\s*\(.*$/', '', $et['Duplex'] ?? $duplex)), 'autoneg' => $et['Auto-negotiation'] ?? '',
      'port_type' => $et['Port'] ?? '', 'lanes' => $et['Lanes'] ?? '', 'max_speed' => $max ?: null, 'master' => $master,
      'bdf' => $bdf, 'driver' => $di['driver'] ?? $driver, 'fw' => $di['firmware-version'] ?? '', 'wireless' => $wl === '1',
      'fec' => null, 'ring' => null, 'module' => null, 'counters' => [], 'other' => [], 'lldp' => null,
      'bond' => null, 'bridge' => null, 'vlans' => [], 'fec_warn' => false,
    ];
    $fec = (string)@file_get_contents("$dir/fec_$name");
    if (preg_match('/Active FEC encodings?:\s*(.+)/', $fec, $a))
      $p['fec'] = ['active' => trim($a[1]), 'configured' => preg_match('/Configured FEC encodings?:\s*(.+)/', $fec, $c) ? trim($c[1]) : ''];
    $ring = (string)@file_get_contents("$dir/ring_$name");
    if (preg_match('/Pre-set maximums:(.*?)Current hardware settings:(.*)$/s', $ring, $r)) {
      $get = fn($s, $k) => preg_match("/^$k:\s*(\d+)/m", $s, $x) ? (int)$x[1] : null;
      $p['ring'] = ['rx' => $get($r[2], 'RX'), 'rx_max' => $get($r[1], 'RX'), 'tx' => $get($r[2], 'TX'), 'tx_max' => $get($r[1], 'TX')];
      if ($p['ring']['rx'] === null && $p['ring']['tx'] === null) $p['ring'] = null;
    }
    $mod = (string)@file_get_contents("$dir/module_$name");
    if (($m['timings']["module_$name"]['rc'] ?? 1) === 0 && trim($mod) !== '') $p['module'] = net_module($mod);

    foreach (NET_COUNTERS as $k => [$label, $class]) {
      if (!isset($now[$name][$k])) continue;
      if (array_intersect(NET_DUPLICATES[$k] ?? [], array_keys($now[$name]))) continue;
      $p['counters'][$k] = ['label' => $label, 'class' => $class, 'now' => $now[$name][$k], 'delta' => $delta($name, $k)];
    }
    foreach ($now[$name] ?? [] as $k => $v) if ($v > 0 && !isset(NET_COUNTERS[$k]) && !str_starts_with($k, 'sys.'))
      $p['other'][$k] = ['now' => $v, 'delta' => $delta($name, $k)];
    $m['ports'][$name] = $p;
  }

  // Bonds, VLANs, bridges
  foreach (glob("$dir/bond_*.txt") ?: [] as $f) {
    $bn = substr(basename($f, '.txt'), 5);
    $b = net_bond((string)file_get_contents($f));
    $old = is_file("$dir/base/bond_$bn.txt") ? net_bond((string)file_get_contents("$dir/base/bond_$bn.txt")) : null;
    foreach ($b['slaves'] as $sn => &$s) {
      $o = $old['slaves'][$sn]['failures'] ?? null;
      $s['failures_delta'] = $o !== null && $s['failures'] >= $o ? $s['failures'] - $o : null;
      if (isset($m['ports'][$sn])) $m['ports'][$sn]['bond'] = $bn;
    }
    unset($s);
    $b['name'] = $bn;
    $m['bonds'][$bn] = $b;
  }
  $vlans = [];
  foreach (net_lines("$dir/vlan.txt") as $l) {
    $p = array_map('trim', explode('|', $l));
    if (count($p) === 3 && ctype_digit($p[1])) $vlans[$p[0]] = ['iface' => $p[0], 'vid' => (int)$p[1], 'parent' => $p[2]];
  }
  $brOf = [];
  foreach (net_lines("$dir/bridges.txt") as $l) {
    [$br, $members, $stp, $vf, $oper, $mtu] = array_pad(explode('|', $l), 6, '');
    $mem = preg_split('/\s+/', trim($members), -1, PREG_SPLIT_NO_EMPTY);
    $m['bridges'][$br] = ['name' => $br, 'members' => $mem, 'stp' => $stp === '1', 'vlan_filtering' => $vf === '1', 'state' => $oper, 'mtu' => (int)$mtu];
    foreach ($mem as $x) $brOf[$x] = $br;
  }
  // Uplinks: each bond, and each physical port that is not in a bond, with its bridge and VLANs.
  $uplinks = array_keys($m['bonds']);
  foreach ($m['ports'] as $n => $p) if (!$p['bond']) $uplinks[] = $n;
  foreach ($uplinks as $u) {
    $vl = [];
    foreach ($vlans as $v) if ($v['parent'] === $u) $vl[] = $v + ['bridge' => $brOf[$v['iface']] ?? ''];
    usort($vl, fn($a, $b) => $a['vid'] <=> $b['vid']);
    $br = $brOf[$u] ?? '';
    if ($br === '' && !$vl && !isset($m['bonds'][$u])) continue;
    $others = [];
    foreach (array_merge([$br], array_column($vl, 'bridge')) as $b) if ($b !== '')
      foreach ($m['bridges'][$b]['members'] ?? [] as $x) if ($x !== $u && !isset($vlans[$x])) $others[$b][] = $x;
    $m['uplinks'][$u] = ['name' => $u, 'members' => isset($m['bonds'][$u]) ? array_keys($m['bonds'][$u]['slaves']) : [$u],
                         'bridge' => $br, 'vlans' => $vl, 'others' => $others];
    foreach ($m['uplinks'][$u]['members'] as $x) if (isset($m['ports'][$x])) {
      $m['ports'][$x]['bridge'] = $br;
      $m['ports'][$x]['vlans'] = array_column($vl, 'vid');
    }
  }

  if (is_file("$dir/lldp.json") && ($m['timings']['lldp.json']['rc'] ?? 1) === 0) {
    $j = json_decode((string)file_get_contents("$dir/lldp.json"), true);
    $m['lldp'] = is_array($j) ? net_lldp($j) : [];
    foreach ($m['lldp'] as $if => $n) if (isset($m['ports'][$if])) $m['ports'][$if]['lldp'] = $n;
  }

  net_cards($dir, $m, $now, $delta);
  net_firmware($m);
  net_problems($m);
  return $m;
}

// Group ports by PCI card (domain:bus:device) and describe each card.
function net_cards(string $dir, array &$m, array $now, callable $delta): void {
  $pci = [];
  foreach (net_lines("$dir/pci.txt") as $l) {
    [$bdf, $ven, $dev, $cs, $cw, $ms, $mw, $up, $ums, $umw, $numa] = array_pad(explode('|', $l), 11, '');
    $pci[$bdf] = ['vendor_id' => $ven, 'device_id' => $dev, 'cur_speed' => net_gts($cs), 'cur_width' => (int)$cw ?: null,
                  'max_speed' => net_gts($ms), 'max_width' => (int)$mw ?: null, 'up' => $up, 'up_speed' => net_gts($ums),
                  'up_width' => (int)$umw ?: null, 'numa' => $numa];
  }
  $temps = [];
  foreach (net_lines("$dir/hwmon.txt") as $l) {
    [$bdf, $hname, $label, $in, $crit, $max, $highest] = array_pad(explode('|', $l), 7, '');
    if (!is_numeric($in)) continue;
    $temps[$bdf][] = ['label' => $label !== '' ? $label : $hname, 'c' => round((int)$in / 1000, 1),
                      'crit' => is_numeric($crit) && (int)$crit > 0 ? round((int)$crit / 1000) : (is_numeric($max) && (int)$max > 0 ? round((int)$max / 1000) : null),
                      'highest' => is_numeric($highest) ? round((int)$highest / 1000, 1) : null];
  }
  foreach ($m['ports'] as $n => $p) {
    $key = preg_match('/^([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2})\.[0-7]$/i', $p['bdf'], $mm) ? $mm[1] : ($p['bdf'] ?: $n);
    $m['cards'][$key]['ports'][] = $n;
    $m['cards'][$key]['functions'][$p['bdf']] = true;
  }
  ksort($m['cards']);
  uasort($m['cards'], fn($a, $b) => !array_filter($a['ports'], fn($n) => $m['ports'][$n]['up']) <=> !array_filter($b['ports'], fn($n) => $m['ports'][$n]['up']));
  foreach ($m['cards'] as $key => &$c) {
    $fns = array_keys($c['functions']);
    sort($fns);
    $f0 = $fns[0];
    $vmm = net_ethtool_kv((string)@file_get_contents("$dir/lspcim_$f0"));
    $vv = (string)@file_get_contents("$dir/lspci_$f0");
    $strip = fn($s) => trim(preg_replace('/\s*\[[0-9a-f]{4}\]$/i', '', (string)$s));
    $vpd = fn($tag) => preg_match('/\[' . $tag . '\][^:]*:\s*(.+)/', $vv, $x) ? trim($x[1]) : '';
    $c['key'] = $key;
    $c['functions'] = $fns;
    $c['vendor'] = $strip($vmm['Vendor'] ?? '');
    $c['device'] = $strip($vmm['Device'] ?? '');
    $c['ids'] = preg_match('/\[([0-9a-f]{4})\]$/i', $vmm['Vendor'] ?? '', $a) && preg_match('/\[([0-9a-f]{4})\]$/i', $vmm['Device'] ?? '', $b) ? "$a[1]:$b[1]" : '';
    $c['product'] = preg_match('/Product Name:\s*(.+)/', $vv, $x) ? trim($x[1]) : '';
    $c['pn'] = $vpd('PN');
    $c['sn'] = $vpd('SN');
    $c['name'] = trim($c['vendor'] . ' ' . $c['device']) ?: ($m['ports'][$c['ports'][0]]['driver'] ?: 'Network device');
    $p0 = $m['ports'][$c['ports'][0]];
    $c['driver'] = $p0['driver'];
    $fws = array_unique(array_filter(array_map(fn($n) => $m['ports'][$n]['fw'], $c['ports'])));
    $c['fw'] = implode(', ', $fws);
    $c['psid'] = preg_match('/\((\w+_\w+)\)/', $c['fw'], $x) ? $x[1] : '';
    $c['wireless'] = $p0['wireless'];

    // Firmware on flash (mstflint query), which can differ from the running one until a reboot.
    $c['mst'] = null;
    foreach ($fns as $f) if (is_file("$dir/mstflint_$f") && ($m['timings']["mstflint_$f"]['rc'] ?? 1) === 0) {
      $q = net_ethtool_kv((string)file_get_contents("$dir/mstflint_$f"));
      $c['mst'] = ['fw' => $q['FW Version'] ?? '', 'date' => $q['FW Release Date'] ?? '', 'psid' => $q['PSID'] ?? '',
                   'product' => $q['Product Version'] ?? '', 'rom' => $q['Rom Info'] ?? '', 'image' => $q['Image type'] ?? ''];
      if ($c['mst']['psid']) $c['psid'] = $c['mst']['psid'];
    }

    // PCIe link: lspci LnkCap/LnkSta, sysfs as fallback, and what the upstream port (slot) can do.
    $pi = $pci[$f0] ?? [];
    $cap = preg_match('/LnkCap:.*?Speed ([\d.]+)GT\/s.*?Width x(\d+)/', $vv, $x) ? [(float)$x[1], (int)$x[2]] : [$pi['max_speed'] ?? null, $pi['max_width'] ?? null];
    $sta = preg_match('/LnkSta:.*?Speed ([\d.]+)GT\/s.*?Width x(\d+)/', $vv, $x) ? [(float)$x[1], (int)$x[2]] : [$pi['cur_speed'] ?? null, $pi['cur_width'] ?? null];
    $need = 0;
    foreach ($c['ports'] as $n) $need += (int)($m['ports'][$n]['max_speed'] ?? 0);
    $c['pcie'] = null;
    if ($sta[0] && $sta[1]) {
      $c['pcie'] = ['cap_speed' => $cap[0], 'cap_width' => $cap[1], 'speed' => $sta[0], 'width' => $sta[1],
        'gbps' => net_pcie_gbps($sta[0], $sta[1]), 'cap_gbps' => net_pcie_gbps($cap[0], $cap[1]), 'need' => $need ? $need / 1000 : null,
        'up' => $pi['up'] ?? '', 'up_speed' => $pi['up_speed'] ?? null, 'up_width' => $pi['up_width'] ?? null, 'numa' => $pi['numa'] ?? ''];
      $pc = &$c['pcie'];
      $pc['degraded'] = ($pc['cap_speed'] && $pc['speed'] < $pc['cap_speed']) || ($pc['cap_width'] && $pc['width'] < $pc['cap_width']);
      $pc['slot_limited'] = ($pc['up_speed'] && $pc['cap_speed'] && $pc['up_speed'] < $pc['cap_speed']) || ($pc['up_width'] && $pc['cap_width'] && $pc['up_width'] < $pc['cap_width']);
      $pc['short'] = $pc['need'] && $pc['gbps'] && $pc['gbps'] < $pc['need'];
      $pc['level'] = $pc['degraded'] ? ($pc['short'] ? 'warn' : 'info') : 'ok';
      unset($pc);
    }

    // Temperatures; dual-port cards report the same ASIC sensor on each function, so keep one per label.
    // mlx5's "ModuleN" sensors are the transceivers, shown with the module instead.
    $c['temps'] = [];
    foreach ($fns as $f) foreach ($temps[$f] ?? [] as $t) {
      if (preg_match('/^Module\d+$/', $t['label']) && $p0['driver'] === 'mlx5_core') continue;
      if (!isset($c['temps'][$t['label']]) || $t['c'] > $c['temps'][$t['label']]['c']) $c['temps'][$t['label']] = $t;
    }
    foreach ($c['temps'] as &$t) $t['level'] = $t['crit'] ? ($t['c'] >= $t['crit'] ? 'crit' : ($t['c'] >= $t['crit'] - 10 ? 'warn' : 'ok')) : 'ok';
    unset($t);

    $c['aer'] = [];
    foreach (['cor' => 'correctable', 'nonfatal' => 'non-fatal', 'fatal' => 'fatal'] as $k => $label) {
      $tot = 0; $d = 0; $have = false; $haveD = true;
      foreach ($fns as $f) if (isset($now["pci:$f"]["aer_$k"])) {
        $have = true; $tot += $now["pci:$f"]["aer_$k"];
        $x = $delta("pci:$f", "aer_$k"); if ($x === null) $haveD = false; else $d += $x;
      }
      if ($have) $c['aer'][$k] = ['label' => $label, 'now' => $tot, 'delta' => $haveD ? $d : null];
    }
  }
  unset($c);
}

// NIC firmware per model, and transceiver part/revision per model; differences are highlighted on the page.
function net_firmware(array &$m): void {
  foreach ($m['cards'] as $c) {
    if (!$c['fw'] && !$c['mst']) continue;
    $model = $c['pn'] ?: ($c['device'] ?: $c['name']);
    $v = preg_replace('/\s*\(.*\)$/', '', $c['fw']) . ($c['psid'] ? " ({$c['psid']})" : '');
    $m['firmware']["NIC: $model"][$v][] = $c['key'];
  }
  foreach ($m['ports'] as $p) {
    $mod = $p['module'];
    if (!$mod || ($mod['vendor'] === '' && $mod['pn'] === '')) continue;
    $m['firmware']["Module: {$mod['vendor']} {$mod['pn']}"]['rev ' . ($mod['rev'] ?: '?')][] = $p['name'];
  }
}

function net_problems(array &$m): void {
  $p = &$m['problems'];
  // $who: the card key, port or bond the problem belongs to, so the page can colour that card.
  // $key names what the problem is about (stable across collections); $value is what it says about it now. An
  // acknowledgement covers one key at one level and one value, so the problem comes back when the value changes
  // (e.g. a counter grows). Readings that drift (temperatures, DOM values) use an empty value.
  $add = function ($level, $text, $who = '', $key = null, $value = null) use (&$p) {
    $p[] = ['level' => $level, 'text' => $text, 'who' => $who, 'key' => $key ?? $text, 'value' => (string)($value ?? $text)];
  };
  $win = $m['window'] !== null ? 'in the last ' . net_duration($m['window']) : 'since the previous collection';
  foreach ($m['errors'] as $e) $add('warn', "Collection: $e");
  foreach ($m['notes'] as $e) $add('info', "Collection: $e");

  foreach ($m['cards'] as $key => $c) {
    $who = $c['name'] . ' (' . implode(', ', $c['ports']) . ')';
    $pc = $c['pcie'];
    // Cards with every port down are left alone: unused cards are expected, and many train their link down while idle.
    $inUse = (bool)array_filter($c['ports'], fn($n) => $m['ports'][$n]['up'] ?? false);
    if ($pc && $pc['degraded'] && $inUse) {
      $txt = "$who: PCIe link runs at {$pc['speed']} GT/s x{$pc['width']} but the card supports {$pc['cap_speed']} GT/s x{$pc['cap_width']}";
      $txt .= $pc['slot_limited'] ? ' (the slot it is in supports at most ' . ($pc['up_speed'] ?? '?') . ' GT/s x' . ($pc['up_width'] ?? '?') . ')' : '';
      $txt .= $pc['short'] ? sprintf('. About %.0f Gb/s is less than the ports can carry (%.0f Gb/s).', $pc['gbps'], $pc['need'])
                           : ($pc['need'] ? sprintf('. About %.0f Gb/s is still enough for its ports (%.0f Gb/s).', $pc['gbps'], $pc['need']) : '.');
      if (!$pc['slot_limited']) $txt .= ' The slot supports the full link, so check the riser or BIOS PCIe settings; some cards also train down while idle.';
      $add($pc['level'], $txt, $key, "pcie|$key", "{$pc['speed']}x{$pc['width']}");
    } elseif ($inUse && $pc && $pc['need'] && $pc['gbps'] && $pc['gbps'] < $pc['need'] * 0.9) {
      $add('info', sprintf("$who: the card's PCIe link (about %.0f Gb/s) cannot carry all ports at full speed at once (%.0f Gb/s).", $pc['gbps'], $pc['need']), $key, "pcie-bw|$key", "{$pc['speed']}x{$pc['width']}");
    }
    foreach ($c['temps'] as $t) if ($t['level'] !== 'ok')
      $add($t['level'], "$who: {$t['label']} temperature {$t['c']} C (critical at {$t['crit']} C)", $key, "temp|$key|{$t['label']}", '');
    if ($c['mst'] && $c['mst']['fw'] !== '' && $c['fw'] !== '' && !str_starts_with($c['fw'], $c['mst']['fw']))
      $add('info', "$who: flash holds firmware {$c['mst']['fw']} but " . preg_replace('/\s*\(.*$/', '', $c['fw']) . ' is running; the flash image takes effect after a reboot.', $key, "mstfw|$key", "{$c['mst']['fw']}|{$c['fw']}");
    foreach (['fatal' => 'crit', 'nonfatal' => 'warn'] as $k => $lvl) if (($c['aer'][$k]['now'] ?? 0) > 0)
      $add($lvl, "$who: {$c['aer'][$k]['now']} PCIe {$c['aer'][$k]['label']} errors (AER) since boot", $key, "aer|$key|$k", $c['aer'][$k]['now']);
    if (($c['aer']['cor']['delta'] ?? 0) > 0) $add('info', "$who: {$c['aer']['cor']['delta']} PCIe correctable errors (AER) $win", $key, "aer-grow|$key", $c['aer']['cor']['now']);
  }

  $rings = [];
  foreach ($m['ports'] as $n => $pt) {
    if ($pt['up'] && $pt['duplex'] === 'half') $add('warn', "$n: half duplex", $n);
    if ($pt['up'] && $pt['speed'] && $pt['max_speed'] && $pt['speed'] < $pt['max_speed'] && stripos($pt['port_type'], 'twisted') !== false)
      $add('info', "$n: linked at " . net_speed_text($pt['speed']) . ' but supports ' . net_speed_text($pt['max_speed']) . ' (cable, switch port or autonegotiation).', $n, "speed|$n", $pt['speed']);

    // 100G on 25G lanes (SR4, CR4, AOC, CWDM4, PSM4) is specified with RS-FEC (Clause 91).
    $mod = $pt['module'];
    $optic = $mod ? trim($mod['type'] . ' ' . $mod['pn']) : '';
    if ($pt['up'] && ($pt['speed'] ?? 0) >= 100000 && $pt['fec'] && preg_match('/^(off|none)$/i', $pt['fec']['active'])
        && preg_match('/AOC|SR4|CR4|SR2|CR2|CWDM4|PSM4|SWDM4/i', $optic)) {
      $m['ports'][$n]['fec_warn'] = true;
      $errs = 0;
      foreach ($pt['counters'] as $x) if ($x['class'] === 'err') $errs += $x['now'];
      $add('warn', "$n: FEC is off on a 100G link with " . ($mod['pn'] ? "a {$mod['pn']} module" : 'this module') . '. 100G links on 25G lanes (AOC, SR4, CR4) are specified with RS-FEC (Clause 91), '
        . "and both ends must use the same FEC mode: check the switch port's FEC setting and change both together (configured here: {$pt['fec']['configured']}). "
        . ($errs ? number_format($errs) . ' receive errors since boot.' : 'No receive errors so far, but the link has no error-correction margin.'), $n,
        "fec|$n", "{$pt['fec']['active']}|{$pt['fec']['configured']}");
    }

    if ($mod) {
      $names = ['temp' => 'temperature', 'volt' => 'voltage', 'bias' => 'laser bias', 'tx' => 'Tx power', 'rx' => 'Rx power'];
      $units = ['temp' => ' C', 'volt' => ' V', 'bias' => ' mA'];
      foreach ($mod['dom'] ? net_module_readings($mod) : [] as $what => $lanes) foreach ($lanes as $lane => $r) {
        if ($r['level'] === 'ok') continue;
        $val = $r['v'] === null ? '' : (isset($units[$what]) ? $r['v'] . $units[$what] : sprintf('%.2f dBm', $r['dbm']));
        $add($pt['up'] ? $r['level'] : 'info', "$n module " . ($mod['pn'] ? "({$mod['pn']}) " : '') . $names[$what] . ($lane ? " lane $lane" : '') . ($val ? " $val" : '')
          . " is outside the module's " . ($r['level'] === 'crit' ? 'alarm' : 'warning') . ' range' . (!empty($r['flag']) ? " ({$r['flag']} flag set)" : '')
          . ($pt['up'] ? '' : ' (port is down)') . '.', $n, "mod|$n|$what|$lane", $r['flag'] ?? '');
      }
      if ($mod['los'] !== '' && !preg_match('/^(none|no)$/i', $mod['los']))
        $add($pt['up'] ? 'warn' : 'info', "$n module reports Rx loss of signal: {$mod['los']}" . ($pt['up'] ? '' : ' (no light from the far end; port is down)'), $n, "los|$n", $mod['los']);
    }

    foreach ($pt['counters'] as $k => $x) {
      $d = $x['delta'];
      $grow = $d !== null && $d > 0;
      $tot = number_format($x['now']);
      switch ($x['class']) {
        case 'link':
          if ($grow && ($k === 'link_down_events_phy' || !isset($pt['counters']['link_down_events_phy'])))
            $add('warn', "$n: link went down $d time" . ($d > 1 ? 's' : '') . " $win ($tot since boot)", $n, "cnt|$n|$k", $x['now']);
          break;
        case 'err':
          if ($grow) $add('warn', "$n: {$x['label']} +" . number_format($d) . " $win ($tot since boot)", $n, "cnt|$n|$k", $x['now']);
          elseif ($x['now'] > 0) $add('info', "$n: $tot {$x['label']} since boot" . ($d === 0 ? ', not growing' : ''), $n, "cnt|$n|$k", $x['now']);
          break;
        case 'fec':
          if ($grow) $add('info', "$n: FEC corrected " . number_format($d) . " bits $win. Normal at a low rate; it means the link relies on FEC.", $n, "cnt|$n|$k", $x['now']);
          break;
        case 'drop':
          $hint = in_array($k, ['rx_out_of_buffer', 'sys.rx_missed_errors'], true)
            ? ' - the host did not take packets off the receive ring in time' . ($pt['ring'] && $pt['ring']['rx_max'] > $pt['ring']['rx'] ? " (ring {$pt['ring']['rx']} of {$pt['ring']['rx_max']})" : '')
            : ($k === 'rx_discards_phy' ? " - the NIC's port buffer overflowed (bursts or flow control)" : '');
          if ($grow) $add('warn', "$n: {$x['label']} +" . number_format($d) . " $win ($tot since boot)$hint", $n, "cnt|$n|$k", $x['now']);
          elseif ($x['now'] > 0) $add('info', "$n: $tot {$x['label']} since boot" . ($d === 0 ? ', not growing' : '') . $hint, $n, "cnt|$n|$k", $x['now']);
          break;
      }
    }
    if ($pt['up'] && $pt['ring'] && (($pt['ring']['rx'] ?? 0) < ($pt['ring']['rx_max'] ?? 0) || ($pt['ring']['tx'] ?? 0) < ($pt['ring']['tx_max'] ?? 0)))
      $rings["RX {$pt['ring']['rx']} of {$pt['ring']['rx_max']}, TX {$pt['ring']['tx']} of {$pt['ring']['tx_max']}"][] = $n;
    if ($pt['up'] && $pt['lldp'] && is_numeric($pt['lldp']['mfs']) && $pt['mtu'] > (int)$pt['lldp']['mfs'])
      $add('info', "$n: MTU {$pt['mtu']} is larger than the maximum frame size the switch reports ({$pt['lldp']['mfs']}).", $n, "mtu|$n", "{$pt['mtu']}|{$pt['lldp']['mfs']}");
  }
  foreach ($rings as $what => $who)
    $add('info', implode(', ', $who) . ": ring buffers are below the maximum ($what). Larger rings absorb bursts at some memory and latency cost (ethtool -G).", $who[0], 'ring|' . implode(',', $who), $what);

  foreach ($m['bonds'] as $bn => $b) {
    $up = array_filter($b['slaves'], fn($s) => $s['mii'] === 'up');
    if ($b['mii'] !== 'up') $add('crit', "$bn: bond is " . ($b['mii'] ?: 'down'), $bn, "bond|$bn|mii", $b['mii']);
    foreach ($b['slaves'] as $sn => $s) {
      if ($s['mii'] !== 'up') $add(count($up) ? 'warn' : 'crit', "$bn: member $sn is " . ($s['mii'] ?: 'down'), $bn, "bond|$bn|$sn|mii", $s['mii']);
      if (($s['failures_delta'] ?? 0) > 0) $add('warn', "$bn: member $sn lost link {$s['failures_delta']} time" . ($s['failures_delta'] > 1 ? 's' : '') . " $win", $bn, "bond|$bn|$sn|fail", $s['failures']);
      elseif ($s['failures'] > 0) $add('info', "$bn: member $sn has {$s['failures']} link failures since boot", $bn, "bond|$bn|$sn|fail", $s['failures']);
      foreach (['actor', 'partner'] as $side) if ($s["{$side}_churn"] === 'churned')
        $add('warn', "$bn: member $sn $side churn state is churned (LACP is not settling)", $bn, "bond|$bn|$sn|churn|$side", '');
    }
    if (stripos($b['mode'], '802.3ad') !== false) {
      if ($up && ($b['partner_mac'] === '' || $b['partner_mac'] === '00:00:00:00:00:00')) $add('warn', "$bn: no LACP partner (the switch ports are not running LACP)", $bn, "bond|$bn|partner", '');
      foreach ($up as $sn => $s) {
        if ($b['agg'] !== null && $s['agg'] !== null && $s['agg'] !== $b['agg'])
          $add('warn', "$bn: $sn is in aggregator {$s['agg']}, not the active aggregator {$b['agg']}, so it carries no traffic (the switch puts it in a different LAG or none)", $bn, "bond|$bn|$sn|agg", "{$s['agg']}|{$b['agg']}");
        elseif ($s['actor_state'] !== null && ($s['actor_state'] & 0x38) !== 0x38)
          $add('warn', "$bn: $sn is not distributing (LACP state: " . net_lacp_state($s['actor_state']) . ')', $bn, "bond|$bn|$sn|dist", $s['actor_state']);
        // The switch side must be in sync, collecting and distributing as well; "defaulted"/"expired" mean this
        // member is using default partner information or has stopped hearing LACPDUs from the switch.
        elseif ($s['partner_state'] !== null && ($s['partner_state'] & 0x38) !== 0x38)
          $add('warn', "$bn: the switch side of $sn is not distributing (partner LACP state: " . net_lacp_state($s['partner_state']) . ')', $bn, "bond|$bn|$sn|pdist", $s['partner_state']);
        elseif ($s['actor_state'] !== null && ($s['actor_state'] & 0xC0))
          $add('warn', "$bn: $sn " . (($s['actor_state'] & 0x80) ? 'stopped receiving LACPDUs from the switch (expired)' : 'uses default partner information (no LACPDUs from the switch)') . ' (LACP state: ' . net_lacp_state($s['actor_state']) . ')', $bn, "bond|$bn|$sn|stale", $s['actor_state']);
      }
      $partners = array_unique(array_filter(array_column($up, 'partner_mac'), fn($x) => $x !== '' && $x !== '00:00:00:00:00:00'));
      if (count($partners) > 1) $add('warn', "$bn: members see different LACP partners (" . implode(', ', $partners) . ')', $bn, "bond|$bn|partners", implode(',', $partners));
      if (preg_match('/^layer2\b/', $b['hash'])) $add('info', "$bn: transmit hash policy {$b['hash']} puts all traffic to one MAC (e.g. the router) on a single member; layer3+4 spreads flows.", $bn, "bond|$bn|hash", $b['hash']);
    }
    $rs = [];
    foreach ($b['slaves'] as $sn => $s) if (!empty($m['ports'][$sn]['ring'])) $rs[$sn] = "RX {$m['ports'][$sn]['ring']['rx']}, TX {$m['ports'][$sn]['ring']['tx']}";
    if (count(array_unique($rs)) > 1)
      $add('info', "$bn: members have different ring sizes (" . implode('; ', array_map(fn($k, $v) => "$k $v", array_keys($rs), $rs)) . ')', $bn, "bond|$bn|rings");
    $speeds = array_unique(array_filter(array_column($up, 'speed')));
    if (count($speeds) > 1) $add('warn', "$bn: members run at different speeds (" . implode(', ', $speeds) . ')', $bn, "bond|$bn|speeds", implode(',', $speeds));
  }

  $order = ['crit' => 0, 'warn' => 1, 'info' => 2];
  usort($p, fn($a, $b) => $order[$a['level']] <=> $order[$b['level']]);
  $m['level'] = net_worst(array_column($p, 'level'));
}

<?PHP
/* Anonymiser for the diagnostics archive. Serial numbers, WWNs, SAS addresses, MAC addresses, NVMe EUIs, PCIe
 * device serial numbers, Mellanox GUIDs, the server's hostname and LLDP switch names (and LLDP management IPs) are
 * replaced. The same input always gives the same token within one archive, and the formats stay valid, so the page
 * can still be rendered from the anonymised files:
 *   SAS address / WWN 5000C500ABCD1224 -> 5000C50 + 5-digit counter + last 16 bits shifted by a per-archive random
 *   offset, e.g. 5000C500000121F7: the NAA and vendor OUI stay, and so do differences in the last 16 bits, so
 *   "HBA base + port number" and "drive WWN + 1/+2" relations hold.
 *   (sg_ses JSON writes SAS addresses as decimal numbers; those are mapped the same way and written back as decimals.)
 *   MAC 02:11:22:33:44:55 -> 02:11:22:00:00:01 (OUI kept). Serials -> SN0001, hostnames -> host1, switches -> switch1.
 */

function st_anon_new(array $hostnames = [], ?int $offset = null): array {
  $st = ['map' => [], 'n' => [], 'literals' => [], 'offset' => $offset ?? random_int(0, 0xFFFF)];
  // Unraid's default names are not identifying, and replacing them would also rewrite "Unraid" in version strings.
  foreach ($hostnames as $hn) if (!in_array(strtolower(trim($hn)), ['unraid', 'tower', 'localhost'], true)) st_anon_literal($st, $hn, 'host');
  return $st;
}

function st_anon_token(array &$st, string $kind, string $key, callable $make): string {
  if (!isset($st['map'][$kind][$key])) {
    $st['n'][$kind] = ($st['n'][$kind] ?? 0) + 1;
    $st['map'][$kind][$key] = $make($st['n'][$kind]);
  }
  return $st['map'][$kind][$key];
}

// Register a literal value (serial, hostname, switch name) to be replaced wherever it appears.
function st_anon_literal(array &$st, string $v, string $kind = 'sn'): void {
  $v = trim($v, " \t\"'");
  if (strlen($v) < 4 || preg_match('/^(n\/?a|none|unknown|not ?(available|set|specified|applicable)|default|to be filled.*|0+|f+|-+|x+)$/i', $v)) return;
  if (ctype_digit($v) && strlen($v) < 6) return;
  if (isset($st['literals'][$v])) return;
  $prefix = ['sn' => 'SN', 'host' => 'host', 'switch' => 'switch'][$kind] ?? $kind;
  $st['literals'][$v] = st_anon_token($st, $kind, strtolower($v), fn($n) => $kind === 'sn' ? sprintf('%s%04d', $prefix, $n) : "$prefix$n");
}

// First pass: find serial numbers (and LLDP switch names) in one file's text.
function st_anon_scan(array &$st, string $name, string $text): void {
  // JSON string values under serial-like keys (lsblk "serial", storcli "Serial Number" / "SN" / "Safe ID", ...).
  if (preg_match_all('/"([^"\n]{1,60})"\s*:\s*"([^"\n]*)"/', $text, $mm, PREG_SET_ORDER))
    foreach ($mm as [, $k, $v]) if (preg_match('/serial|^sn$|^msn$|safe id|guid|uuid/i', trim($k))) st_anon_literal($st, $v);
  // SES descriptors: "TP=9C;SN=PMW825620090699;FW=0311;", "MSN=..".
  if (preg_match_all('/(?:^|[;"\s])[A-Z]{0,3}SN=([^;"\n]+)/', $text, $mm)) foreach ($mm[1] as $v) st_anon_literal($st, $v);
  // "Serial Number: X", ethtool -m "Vendor SN : X", lspci VPD "[SN] Serial number: X", ini serial="X".
  if (preg_match_all('/(?:serial number|vendor sn|\[SN\][^:\n]*)\s*:\s*(\S[^\n]*?)\s*$/im', $text, $mm)) foreach ($mm[1] as $v) st_anon_literal($st, $v);
  if (preg_match_all('/^\s*serial\s*=\s*"?([^"\n]*)"?\s*$/im', $text, $mm)) foreach ($mm[1] as $v) st_anon_literal($st, $v);
  if ($name === 'lldp.json' && is_array($j = json_decode($text, true)) && function_exists('net_lldp'))
    foreach (net_lldp($j) as $n) if ($n['switch'] !== '') st_anon_literal($st, $n['switch'], 'switch');
}

function st_anon_sas(array &$st, string $hex): string {
  $l = strtolower($hex);
  $hi = substr($l, 0, 12);
  $new = st_anon_token($st, 'sas', $hi, fn($n) => substr($hi, 0, 7) . sprintf('%05x', $n)) . sprintf('%04x', (hexdec(substr($l, 12)) + $st['offset']) & 0xFFFF);
  return ctype_upper(preg_replace('/[^a-zA-Z]/', '', $hex) ?: 'a') ? strtoupper($new) : $new;
}

// Second pass: replace everything in one file's text. (Callbacks take $st by reference: tokens must persist.)
function st_anon_text(array &$st, string $name, string $text): string {
  // storcli's raw SCSI inquiry dump spells the serial number out in hex.
  $text = preg_replace('/("Inquiry Data"\s*:\s*)"[^"]*"/', '$1"(removed)"', $text);
  // lspci VPD vendor-specific fields carry serials, UUIDs and MACs in free form; the page does not use them.
  $text = preg_replace('/(\[V[0-9A-Z]\] Vendor specific:\s*)\S.*$/m', '$1(removed)', $text);
  // Literals, longest first, as whole words.
  $lits = $st['literals'];
  uksort($lits, fn($a, $b) => strlen($b) <=> strlen($a));
  // Long serials are also replaced inside longer strings (lspci VPD "[VU] Vendor specific: <serial>MLNXS0D0F0").
  foreach ($lits as $v => $tok) $text = preg_replace(strlen($v) >= 8 && preg_match('/\d/', $v) && preg_match('/[A-Za-z]/', $v)
    ? '/' . preg_quote($v, '/') . '/i' : '/(?<![A-Za-z0-9])' . preg_quote($v, '/') . '(?![A-Za-z0-9])/i', $tok, $text);
  // NAA 5 SAS addresses and WWNs, with or without 0x.
  $text = preg_replace_callback('/(?<![0-9A-Za-z])(0x)?(5[0-9A-Fa-f]{15})(?![0-9A-Za-z])/', function ($m) use (&$st) { return $m[1] . st_anon_sas($st, $m[2]); }, $text);
  // The same as 19-digit decimals (sg_ses JSON): 0x5000000000000000 .. 0x5FFFFFFFFFFFFFFF.
  $text = preg_replace_callback('/(?<![0-9.])(5[7-9]\d{17}|6[0-8]\d{17}|69[01]\d{16})(?![0-9.])/', function ($m) use (&$st) {
    $v = (int)$m[1];
    if ($v < 0x5000000000000000 || $v > 0x5FFFFFFFFFFFFFFF) return $m[1];
    return (string)hexdec(st_anon_sas($st, sprintf('%016x', $v)));
  }, $text);
  // 128-bit NAA 6 WWNs (RAID volumes), NVMe/SCSI name strings.
  $text = preg_replace_callback('/(?<![0-9A-Za-z])(0x)?(6[0-9A-Fa-f]{31})(?![0-9A-Za-z])/', function ($m) use (&$st) { return $m[1] . '6' . st_anon_token($st, 'naa6', strtolower($m[2]), fn($n) => sprintf('%031x', $n)); }, $text);
  $text = preg_replace_callback('/\b(eui\.|nvme\.|naa\.|t10\.)([0-9A-Za-z._-]+)/', function ($m) use (&$st) { return $m[1] . st_anon_token($st, 'nvme', $m[0], fn($n) => sprintf('anon%04d', $n)); }, $text);
  // PCIe Device Serial Number (lspci -vv), Mellanox GUIDs and base MACs (mstflint q).
  $text = preg_replace_callback('/(Device Serial Number )([0-9a-fA-F]{2}(?:-[0-9a-fA-F]{2}){7})/', function ($m) use (&$st) { return $m[1] . st_anon_token($st, 'dsn', strtolower($m[2]),
    fn($n) => substr($m[2], 0, 9) . '-' . implode('-', str_split(sprintf('%010x', $n), 2))); }, $text);
  $text = preg_replace_callback('/((?:GUID|MAC)\s*:\s*)([0-9a-fA-F]{12,16})\b/', function ($m) use (&$st) { return $m[1] . st_anon_token($st, 'guid', strtolower($m[2]),
    fn($n) => substr($m[2], 0, 6) . sprintf('%0' . (strlen($m[2]) - 6) . 'x', $n)); }, $text);
  // MAC addresses (all-zero and broadcast stay: they mean "none").
  $text = preg_replace_callback('/(?<![0-9A-Fa-f:])((?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2})(?![0-9A-Fa-f:])/', function ($m) use (&$st) {
    $l = strtolower($m[1]);
    if ($l === '00:00:00:00:00:00' || $l === 'ff:ff:ff:ff:ff:ff') return $m[1];
    $new = st_anon_token($st, 'mac', $l, fn($n) => substr($l, 0, 9) . implode(':', str_split(sprintf('%06x', $n), 2)));
    return preg_match('/[A-F]/', $m[1]) ? strtoupper($new) : $new;
  }, $text);
  // LLDP management addresses.
  if ($name === 'lldp.json') {
    $text = preg_replace_callback('/(?<![0-9.])(\d{1,3}(?:\.\d{1,3}){3})(?![0-9.])/', function ($m) use (&$st) { return st_anon_token($st, 'ip4', $m[1], fn($n) => '192.0.2.' . $n); }, $text);
    $text = preg_replace_callback('/(?<![0-9A-Za-z:])([0-9a-fA-F]{1,4}(?::[0-9a-fA-F]{0,4}){2,7})(?![0-9A-Za-z:])/', function ($m) use (&$st) { return substr_count($m[1], ':') >= 2 && preg_match('/[0-9a-f]{3}/i', $m[1])
      ? st_anon_token($st, 'ip6', strtolower($m[1]), fn($n) => sprintf('2001:db8::%x', $n)) : $m[1]; }, $text);
  }
  return $text;
}

// disks.ini / devs.ini reduced to what the page needs to name disks: name, device, type, status.
function st_anon_ini(string $text, bool $unassigned): string {
  $ini = @parse_ini_string($text, true, INI_SCANNER_RAW) ?: [];
  $out = ''; $i = 0;
  foreach ($ini as $key => $d) {
    if (!is_array($d)) continue;
    $sec = $unassigned ? 'dev' . (++$i) : ($d['name'] ?? 'disk' . (++$i));
    $out .= '["' . str_replace('"', '', $sec) . "\"]\n";
    foreach (['name', 'device', 'type', 'status'] as $k) if (isset($d[$k])) $out .= "$k=\"" . str_replace('"', '', $d[$k]) . "\"\n";
  }
  return $out;
}

<?PHP
/* PCI Topology model: reads what scripts/collect-pci.sh left in the cache and builds the page model.
 *   devices   every PCI function (lspci -vvv + sysfs): name, role, driver, IOMMU group, NUMA node, link at both ends,
 *             ASPM, resizable BARs, SR-IOV, AER counters, IRQs and the VMs it is passed through to.
 *   roots     the PCI host bridges (root complexes) and the tree below each.
 *   cpu       sockets, NUMA nodes (and the NPS mode they suggest), L3 domains (CCX) grouped into CCDs, isolated CPUs.
 *   mem       memory channels and DIMMs (dmidecode type 17) with EDAC error counters, hugepages.
 *   vms       libvirt VMs: vCPU pinning, memory, hugepages, PCI passthrough (reduced by scripts/vms.php).
 *   problems  downtrained links, AER errors, ECC errors, memory population, IRQs on isolated CPUs, NUMA/CCD placement,
 *             passthrough and hugepage issues. Same shape as the other pages (level, text, who, key, value).
 */

const PCI_CACHE = '/var/local/storage-topology/pci/current';

function pci_lines(string $file): array { return @file($file, FILE_IGNORE_NEW_LINES) ?: []; }

function pci_worst(array $levels): string {
  foreach (['crit', 'warn', 'info', 'ok'] as $l) if (in_array($l, $levels, true)) return $l;
  return 'ok';
}

// "0-7,64-71" -> [0..7, 64..71]; libvirt's "0-7,^3" excludes 3; the kernel's "0-31:2/8" takes 2 of every 8.
function pci_cpus(string $list): array {
  $out = []; $not = [];
  foreach (explode(',', trim($list)) as $part) {
    $part = trim($part);
    $neg = str_starts_with($part, '^');
    if ($neg) $part = substr($part, 1);
    $set = [];
    if (preg_match('/^(\d+)-(\d+)(?::(\d+)\/(\d+))?$/', $part, $mm)) {
      $use = isset($mm[3]) ? (int)$mm[3] : 1; $grp = isset($mm[4]) ? max(1, (int)$mm[4]) : 1;
      for ($i = (int)$mm[1]; $i <= (int)$mm[2]; $i++) if (($i - (int)$mm[1]) % $grp < $use) $set[] = $i;
    } elseif (preg_match('/^\d+$/', $part)) $set[] = (int)$part;
    if ($neg) $not = array_merge($not, $set); else $out = array_merge($out, $set);
  }
  $out = array_values(array_diff(array_unique($out), $not));
  sort($out);
  return $out;
}

// [0,1,2,3,8] -> "0-3,8"
function pci_cpulist(array $cpus): string {
  $cpus = array_values(array_unique(array_map('intval', $cpus)));
  sort($cpus);
  $out = []; $i = 0; $n = count($cpus);
  while ($i < $n) {
    $j = $i;
    while ($j + 1 < $n && $cpus[$j + 1] === $cpus[$j] + 1) $j++;
    $out[] = $j > $i ? "{$cpus[$i]}-{$cpus[$j]}" : (string)$cpus[$i];
    $i = $j + 1;
  }
  return implode(',', $out);
}

// PCIe generation of a transfer rate.
function pci_gen(?float $gt): string {
  if (!$gt) return '-';
  foreach ([[2.5, 1], [5, 2], [8, 3], [16, 4], [32, 5], [64, 6]] as [$r, $g]) if (abs($gt - $r) < 0.01) return "Gen$g";
  return rtrim(rtrim(sprintf('%.1f', $gt), '0'), '.') . ' GT/s';
}

// Usable bandwidth in GB/s per direction: 8b/10b to 5 GT/s, 128b/130b from 8 GT/s (Gen6 FLIT counted the same).
function pci_gbs(?float $gt, ?int $width): ?float {
  if (!$gt || !$width) return null;
  return $gt * $width * ($gt >= 8 ? 128 / 130 : 0.8) / 8;
}

function pci_link_text(?float $gt, ?int $w): string { return $gt || $w ? pci_gen($gt) . ' x' . ($w ?? '?') : '-'; }

function pci_bytes_text(?int $b): string {
  if ($b === null) return '-';
  foreach (['TiB' => 40, 'GiB' => 30, 'MiB' => 20, 'KiB' => 10] as $u => $s) if ($b >= 1 << $s) {
    $v = $b / (1 << $s);
    return (abs($v - round($v)) < 0.05 ? (string)round($v) : sprintf('%.1f', $v)) . " $u";
  }
  return "$b B";
}

// "64 GiB", "16384 MB", "32GB" -> bytes
function pci_size_bytes(string $s): ?int {
  if (!preg_match('/([\d.]+)\s*(?:([KMGT])(?:i?B)?|bytes?|B)\b/i', $s, $mm)) return null;
  $mult = ['K' => 10, 'M' => 20, 'G' => 30, 'T' => 40][strtoupper($mm[2] ?? '')] ?? 0;
  return (int)round((float)$mm[1] * (1 << $mult));
}

// Shorter vendor names for the tree ("Advanced Micro Devices, Inc. [AMD]" -> "AMD").
function pci_short_name(string $name): string {
  $map = ['/^Advanced Micro Devices, Inc\. \[AMD(?:\/ATI)?\]/' => 'AMD', '/^NVIDIA Corporation/' => 'NVIDIA', '/^Intel Corporation/' => 'Intel',
          '/^Mellanox Technologies/' => 'Mellanox', '/^Broadcom \/ LSI/' => 'Broadcom', '/^Samsung Electronics Co Ltd/' => 'Samsung',
          '/^Sandisk Corp/' => 'SanDisk', '/^Phison Electronics Corporation/' => 'Phison', '/^Silicon Motion, Inc\./' => 'Silicon Motion',
          '/^ASPEED Technology, Inc\./' => 'ASPEED', '/^Aquantia Corp\./' => 'Aquantia', '/^Realtek Semiconductor Co\., Ltd\./' => 'Realtek',
          '/^Micron Technology Inc/' => 'Micron', '/^Kingston Technology Company, Inc\./' => 'Kingston', '/^Marvell Technology Group Ltd\./' => 'Marvell',
          '/^ASMedia Technology Inc\./' => 'ASMedia', '/^HighPoint Technologies, Inc\./' => 'HighPoint', '/^Western Digital/' => 'WD'];
  foreach ($map as $re => $short) $name = preg_replace($re, $short, $name);
  return $name;
}

// ---------------------------------------------------------------------------------------------------------------
// lspci -D -nn -vvv: one block per function.
function pci_parse_lspci(string $text): array {
  $out = [];
  foreach (preg_split('/\n(?=[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7] )/', $text) as $block) {
    if (!preg_match('/^([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]) (.+?) \[([0-9a-f]{4})\]: (.+?) \[([0-9a-f]{4}):([0-9a-f]{4})\](?: \(rev ([0-9a-f]+)\))?(?: \(prog-if [^)]*\))?\s*$/m', $block, $mm)) continue;
    $d = ['bdf' => $mm[1], 'classname' => $mm[2], 'name' => $mm[4], 'rev' => $mm[7] ?? '', 'subsystem' => '', 'slot' => '', 'type' => '',
          'lnkcap' => null, 'lnksta' => null, 'aspm_cap' => '', 'aspm' => '', 'dlactive' => null, 'rebar' => [], 'modules' => '', 'driver' => '',
          'lane_err' => '', 'acs' => null];
    if (preg_match('/^\tSubsystem: (.+?)(?: \[([0-9a-f]{4}):([0-9a-f]{4})\])?$/m', $block, $s)) $d['subsystem'] = $s[1];
    if (preg_match('/^\tPhysical Slot: (.+)$/m', $block, $s)) $d['slot'] = trim($s[1]);
    if (preg_match('/Express \(v\d\) ([^,(]+?)\s*(?:\(Slot[+-]\))?,/', $block, $s)) $d['type'] = trim($s[1]);
    if (preg_match('/LnkCap:\s+Port #\d+, Speed ([\d.]+)GT\/s, Width x(\d+), ASPM ([^,\n]+)/', $block, $s)) {
      $d['lnkcap'] = [(float)$s[1], (int)$s[2]];
      $d['aspm_cap'] = trim($s[3]);
    }
    if (preg_match('/LnkCtl:\s+ASPM ([^;\n]+);/', $block, $s)) $d['aspm'] = trim($s[1]);
    if (preg_match('/LnkSta:\s+Speed ([\d.]+)GT\/s[^,]*, Width x(\d+)/', $block, $s)) $d['lnksta'] = [(float)$s[1], (int)$s[2]];
    if (preg_match('/DLActive([+-])/', $block, $s)) $d['dlactive'] = $s[1] === '+';
    if (preg_match('/LaneErrStat: LaneErr at lane: ([\d ]+)/', $block, $s)) $d['lane_err'] = trim($s[1]);
    if (preg_match('/ACSCtl:\s+SrcValid([+-])/', $block, $s)) $d['acs'] = $s[1] === '+';
    if (preg_match('/Physical Resizable BAR\n((?:\t\t.*\n?)+)/', $block, $s))
      foreach (explode("\n", $s[1]) as $l) if (preg_match('/BAR (\d): current size: (\S+?), supported: (.+)$/', $l, $b)) {
        $sup = preg_split('/\s+/', trim($b[3]));
        $d['rebar'][(int)$b[1]] = ['current' => $b[2], 'max' => end($sup), 'cur_bytes' => pci_size_bytes($b[2]), 'max_bytes' => pci_size_bytes((string)end($sup))];
      }
    if (preg_match('/^\tKernel driver in use: (\S+)/m', $block, $s)) $d['driver'] = $s[1];
    if (preg_match('/^\tKernel modules: (.+)$/m', $block, $s)) $d['modules'] = trim($s[1]);
    $out[$d['bdf']] = $d;
  }
  return $out;
}

// grep -H output of sysfs attributes: [bdf][attr] = value (multi-line files such as aer_dev_* become arrays).
function pci_parse_attrs(array $lines): array {
  $out = [];
  foreach ($lines as $l) {
    if (!preg_match('#^/sys/bus/pci/devices/([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7])/([a-z0-9_/]+):(.*)$#', $l, $mm)) continue;
    [, $bdf, $attr, $v] = $mm;
    if (str_starts_with($attr, 'aer_dev_')) { if (preg_match('/^(\S+) (\d+)$/', $v, $a)) $out[$bdf][$attr][$a[1]] = (int)$a[2]; }
    else $out[$bdf][$attr] = trim($v);
  }
  return $out;
}

// dmidecode output: list of [type, fields].
function pci_parse_dmi(string $text): array {
  $out = [];
  foreach (preg_split('/\n\s*\n/', $text) as $block) {
    if (!preg_match('/^Handle 0x[0-9A-F]+, DMI type (\d+)/m', $block, $mm)) continue;
    $f = [];
    foreach (explode("\n", $block) as $l) if (preg_match('/^\t([^\t:][^:]*):\s*(.*)$/', $l, $kv)) $f[trim($kv[1])] = trim($kv[2]);
    $out[] = ['type' => (int)$mm[1], 'f' => $f];
  }
  return $out;
}

// One DIMM slot from dmidecode type 17. Channel from "P0 CHANNEL A" (AMD boards), "CHANNEL A DIMM 0", "DIMM_A1" etc.
function pci_dimm(array $f): array {
  $size = $f['Size'] ?? '';
  $loc = $f['Locator'] ?? ''; $bank = $f['Bank Locator'] ?? '';
  $chan = null; $sock = 0;
  if (preg_match('/(?:CHANNEL|CH)[\s_-]*([A-Z])\b/i', "$bank $loc", $mm)) $chan = strtoupper($mm[1]);
  elseif (preg_match('/(?:^|[_\s-])(?:DIMM)?[_\s-]?([A-P])(\d)\s*$/i', $loc, $mm)) $chan = strtoupper($mm[1]);
  if (preg_match('/\bP(\d)\b|CPU[_\s-]?(\d)|NODE\s*(\d)/i', "$bank $loc", $mm)) $sock = (int)(array_values(array_filter(array_slice($mm, 1), 'strlen'))[0] ?? 0);
  $mts = fn($s) => preg_match('/(\d+)\s*MT\/s|(\d+)\s*MHz/', (string)$s, $m2) ? (int)($m2[1] ?: $m2[2]) : null;
  $clean = fn($s) => preg_match('/^(Not Specified|Unknown|None|NO DIMM|Undefined|\s*)$/i', trim((string)$s)) ? '' : trim((string)$s);
  return [
    'locator' => $loc, 'bank' => $bank, 'channel' => $chan ?? ($bank !== '' ? $bank : $loc), 'channel_known' => $chan !== null, 'socket' => $sock,
    'present' => !preg_match('/No Module Installed|^0\b|^$|Not Installed/i', $size),
    'bytes' => pci_size_bytes($size), 'type' => $clean($f['Type'] ?? ''), 'detail' => $clean($f['Type Detail'] ?? ''),
    'rated' => $mts($f['Speed'] ?? ''), 'configured' => $mts($f['Configured Memory Speed'] ?? ($f['Configured Clock Speed'] ?? '')),
    'vendor' => $clean($f['Manufacturer'] ?? ''), 'part' => $clean($f['Part Number'] ?? ''), 'serial' => $clean($f['Serial Number'] ?? ''),
    'rank' => $clean($f['Rank'] ?? ''), 'ecc_width' => isset($f['Total Width'], $f['Data Width']) && (int)$f['Total Width'] > (int)$f['Data Width'],
  ];
}

// EDAC sysfs (grep -H): controllers and their ranks/DIMMs, grouped per channel.
function pci_parse_edac(array $lines): array {
  $mcs = [];
  foreach ($lines as $l) {
    if (!preg_match('#/edac/mc/mc(\d+)/(?:((?:rank|dimm)\d+)/)?([a-z_]+):(.*)$#', $l, $mm)) continue;
    [, $mc, $unit, $attr, $v] = $mm;
    $mc = (int)$mc; $v = trim($v);
    $mcs[$mc] ??= ['mc' => $mc, 'name' => '', 'size_mb' => null, 'ce' => 0, 'ue' => 0, 'ce_noinfo' => 0, 'ue_noinfo' => 0, 'since' => null, 'units' => []];
    if ($unit === '') {
      match ($attr) {
        'mc_name' => $mcs[$mc]['name'] = $v, 'size_mb' => $mcs[$mc]['size_mb'] = (int)$v, 'ce_count' => $mcs[$mc]['ce'] = (int)$v,
        'ue_count' => $mcs[$mc]['ue'] = (int)$v, 'ce_noinfo_count' => $mcs[$mc]['ce_noinfo'] = (int)$v, 'ue_noinfo_count' => $mcs[$mc]['ue_noinfo'] = (int)$v,
        'seconds_since_reset' => $mcs[$mc]['since'] = (int)$v, default => null,
      };
    } else $mcs[$mc]['units'][$unit][$attr] = $v;
  }
  foreach ($mcs as &$c) {
    $chans = [];
    foreach ($c['units'] as $u) {
      $loc = $u['dimm_location'] ?? '';
      $ch = preg_match('/channel (\d+)/', $loc, $mm) ? (int)$mm[1] : (preg_match('/^\D*(\d+)/', $loc, $mm) ? (int)$mm[1] : 0);
      $chans[$ch] ??= ['channel' => $ch, 'ranks' => 0, 'size_mb' => 0, 'ce' => 0, 'ue' => 0, 'label' => '', 'mem_type' => '', 'mode' => ''];
      $x = &$chans[$ch];
      $x['ranks']++;
      $x['size_mb'] += (int)($u['size'] ?? 0);
      $x['ce'] += (int)($u['dimm_ce_count'] ?? 0);
      $x['ue'] += (int)($u['dimm_ue_count'] ?? 0);
      $lab = $u['dimm_label'] ?? '';
      if ($x['label'] === '' && $lab !== '' && !preg_match('/^mc#\d+/', $lab)) $x['label'] = $lab;
      $x['mem_type'] = $u['dimm_mem_type'] ?? $x['mem_type'];
      $x['mode'] = $u['dimm_edac_mode'] ?? $x['mode'];
      unset($x);
    }
    ksort($chans);
    $c['channels'] = $chans;
    unset($c['units']);
  }
  unset($c);
  ksort($mcs);
  return $mcs;
}

// ---------------------------------------------------------------------------------------------------------------
// libvirt domain XML (config or live <domstatus>) -> the facts the page uses.
function pci_vm_parse(string $xml): ?array {
  if (trim($xml) === '') return null;
  $prev = libxml_use_internal_errors(true);
  $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
  libxml_use_internal_errors($prev);
  if ($doc === false) return null;
  $dom = $doc->getName() === 'domain' ? $doc : ($doc->domain ?? null);
  if (!$dom || (string)$dom->name === '') return null;
  $kib = function ($n) {
    $v = (float)$n; $u = strtolower((string)($n['unit'] ?? 'KiB'));
    return (int)round($v * (['b' => 1 / 1024, 'bytes' => 1 / 1024, 'k' => 1, 'kib' => 1, 'kb' => 1000 / 1024, 'm' => 1024, 'mib' => 1024, 'mb' => 1e6 / 1024,
      'g' => 1048576, 'gib' => 1048576, 'gb' => 1e9 / 1024][$u] ?? 1));
  };
  $vm = ['name' => (string)$dom->name, 'vcpus' => (int)$dom->vcpu, 'mem_kib' => isset($dom->memory) ? $kib($dom->memory) : 0, 'huge_kib' => null,
         'pins' => [], 'emu' => '', 'numa' => '', 'numa_mode' => '', 'hostdevs' => []];
  if (isset($dom->memoryBacking->hugepages)) {
    // No <page>: the default hugepage size (0 here, resolved by the page).
    $page = $dom->memoryBacking->hugepages->page[0] ?? null;
    $vm['huge_kib'] = 0;
    if ($page) { $v = (float)$page['size']; $u = strtolower((string)($page['unit'] ?: 'KiB')); $vm['huge_kib'] = (int)round($v * (['k' => 1, 'kib' => 1, 'm' => 1024, 'mib' => 1024, 'g' => 1048576, 'gib' => 1048576][$u] ?? 1)); }
  }
  foreach ($dom->cputune->vcpupin ?? [] as $p) $vm['pins'][(int)$p['vcpu']] = (string)$p['cpuset'];
  if (isset($dom->cputune->emulatorpin)) $vm['emu'] = (string)$dom->cputune->emulatorpin['cpuset'];
  if (isset($dom->numatune->memory)) { $vm['numa'] = (string)$dom->numatune->memory['nodeset']; $vm['numa_mode'] = (string)$dom->numatune->memory['mode'] ?: 'strict'; }
  // PCI passthrough: <hostdev type='pci'> and SR-IOV/NIC passthrough as <interface type='hostdev'>.
  $hex = fn($s, $w) => str_pad(strtolower(preg_replace('/^0x/i', '', (string)$s)), $w, '0', STR_PAD_LEFT);
  $srcs = [];
  foreach ($dom->devices->hostdev ?? [] as $h) if ((string)$h['type'] === 'pci') $srcs[] = $h->source->address ?? null;
  foreach ($dom->devices->interface ?? [] as $h) if ((string)$h['type'] === 'hostdev') {
    $a = $h->source->address ?? null;
    if ($a && in_array((string)($a['type'] ?: 'pci'), ['pci'], true)) $srcs[] = $a;
  }
  foreach ($srcs as $a) if ($a && (string)$a['bus'] !== '')
    $vm['hostdevs'][] = $hex($a['domain'] ?: '0', 4) . ':' . $hex($a['bus'], 2) . ':' . $hex($a['slot'], 2) . '.' . (int)hexdec(preg_replace('/^0x/i', '', (string)$a['function']));
  $vm['hostdevs'] = array_values(array_unique($vm['hostdevs']));
  ksort($vm['pins']);
  return $vm;
}

function pci_vm_lines(array $vm, bool $running): array {
  $n = str_replace(["|", "\n"], ['/', ' '], $vm['name']);
  $out = ["vm|$n|" . ($running ? 1 : 0) . "|{$vm['vcpus']}|{$vm['mem_kib']}|" . ($vm['huge_kib'] ?? '')];
  foreach ($vm['pins'] as $v => $set) $out[] = "pin|$n|$v|$set";
  if ($vm['emu'] !== '') $out[] = "emu|$n|{$vm['emu']}";
  if ($vm['numa'] !== '') $out[] = "numa|$n|{$vm['numa']}|" . ($vm['numa_mode'] ?? 'strict');
  foreach ($vm['hostdevs'] as $b) $out[] = "hostdev|$n|$b";
  return $out;
}

function pci_parse_vms(array $lines): array {
  $vms = [];
  foreach ($lines as $l) {
    $f = explode('|', $l);
    $n = $f[1] ?? '';
    if ($f[0] === 'vm' && count($f) >= 6) $vms[$n] = ['name' => $n, 'running' => $f[2] === '1', 'vcpus' => (int)$f[3], 'mem_kib' => (int)$f[4],
      'huge_kib' => $f[5] === '' ? null : (int)$f[5], 'pins' => [], 'emu' => '', 'numa' => '', 'numa_mode' => '', 'hostdevs' => []];
    elseif (!isset($vms[$n])) continue;
    elseif ($f[0] === 'pin') $vms[$n]['pins'][(int)$f[2]] = $f[3] ?? '';
    elseif ($f[0] === 'emu') $vms[$n]['emu'] = $f[2] ?? '';
    elseif ($f[0] === 'numa') { $vms[$n]['numa'] = $f[2] ?? ''; $vms[$n]['numa_mode'] = ($f[3] ?? '') ?: 'strict'; }
    elseif ($f[0] === 'hostdev') $vms[$n]['hostdevs'][] = $f[2] ?? '';
  }
  ksort($vms, SORT_NATURAL | SORT_FLAG_CASE);
  return $vms;
}

// Kernel command line parameters ("isolcpus=managed_irq,domain,8-15" -> ['isolcpus' => ...]).
function pci_cmdline(string $s): array {
  $out = [];
  foreach (preg_split('/\s+/', trim($s)) as $w) if ($w !== '') { [$k, $v] = array_pad(explode('=', $w, 2), 2, ''); $out[$k] = isset($out[$k]) ? "{$out[$k]} $v" : $v; }
  return $out;
}

// ---------------------------------------------------------------------------------------------------------------
function pci_load(string $dir = PCI_CACHE): array {
  $m = ['collected' => (int)trim((string)@file_get_contents("$dir/done")) ?: null, 'timings' => [], 'problems' => [], 'devices' => [], 'roots' => [],
        'groups' => [], 'cpu' => [], 'mem' => [], 'vms' => [], 'cmdline' => [], 'acs_override' => '', 'notes' => []];
  foreach (pci_lines("$dir/timings") as $l) { [$n, $rc, $ms] = array_pad(explode('|', $l), 3, '0'); $m['timings'][$n] = ['rc' => (int)$rc, 'ms' => (int)$ms]; }
  foreach ($m['timings'] as $n => $t) if ($t['rc'] !== 0 && !($n === 'vms.txt' && !is_dir('/etc/libvirt/qemu')))
    $m['problems'][] = ['level' => 'info', 'text' => "Collection: $n exited with " . ($t['rc'] === 124 || $t['rc'] === 137 ? 'a timeout' : "code {$t['rc']}")
      . (($e = trim((string)@file_get_contents("$dir/$n.err"))) !== '' ? ': ' . mb_substr(strtok($e, "\n"), 0, 160) : ''), 'who' => '', 'key' => "collect|$n", 'value' => (string)$t['rc']];
  $m['cmdline'] = pci_cmdline((string)@file_get_contents("$dir/cmdline.txt"));
  $m['acs_override'] = $m['cmdline']['pcie_acs_override'] ?? '';
  pci_cpu($dir, $m);
  pci_devices($dir, $m);
  $m['vms'] = pci_parse_vms(pci_lines("$dir/vms.txt"));
  pci_memory($dir, $m);
  pci_problems($m);
  $order = ['crit' => 0, 'warn' => 1, 'info' => 2];
  usort($m['problems'], fn($a, $b) => $order[$a['level']] <=> $order[$b['level']]);
  return $m;
}

// CPU: packages, L3 domains (CCX) and CCDs, NUMA nodes and the NPS mode they suggest, isolated CPUs.
function pci_cpu(string $dir, array &$m): void {
  $info = [];
  foreach (pci_lines("$dir/cpuinfo.txt") as $l) if (preg_match('/^([^:]+?)\s*:\s*(.*)$/', $l, $mm)) $info[$mm[1]] ??= $mm[2];
  $cpus = []; $cache = [];
  foreach (pci_lines("$dir/cputopo.txt") as $l) {
    if (preg_match('#/cpu(\d+)/topology/(\w+):(.*)$#', $l, $mm)) $cpus[(int)$mm[1]][$mm[2]] = trim($mm[3]);
    elseif (preg_match('#/cpu(\d+)/cache/index3/(\w+):(.*)$#', $l, $mm)) $cpus[(int)$mm[1]]['l3_' . $mm[2]] = trim($mm[3]);
    if (preg_match('#/cpu0/cache/index([0-2])/(\w+):(.*)$#', $l, $mm)) $cache[(int)$mm[1]][$mm[2]] = trim($mm[3]);
  }
  ksort($cpus);
  $sets = [];
  foreach (pci_lines("$dir/cpusets.txt") as $l) { [$k, $v] = array_pad(explode('|', $l, 2), 2, ''); $sets[$k] = $v; }
  $isolated = pci_cpus($sets['isolated'] ?? '');
  // isolcpus from the command line too (flags such as managed_irq, domain, nohz).
  $iso = $m['cmdline']['isolcpus'] ?? '';
  $isoFlags = array_values(array_filter(explode(',', $iso), fn($x) => preg_match('/^[a-z_]+$/', $x)));
  $nodes = []; $huge = [];
  foreach (pci_lines("$dir/nodes.txt") as $l) {
    $f = explode('|', $l);
    if ($f[0] === 'node') $nodes[(int)$f[1]] = ['node' => (int)$f[1], 'cpus' => pci_cpus($f[2] ?? ''), 'mem_kib' => (int)($f[3] ?? 0), 'distance' => trim($f[4] ?? '')];
    elseif ($f[0] === 'huge' && preg_match('/^(\d+)kB$/', $f[2] ?? '', $mm)) {
      $kb = (int)$mm[1];
      $huge[$kb] ??= ['kib' => $kb, 'total' => 0, 'free' => 0, 'surplus' => 0, 'nodes' => []];
      $huge[$kb]['total'] += (int)$f[3]; $huge[$kb]['free'] += (int)$f[4]; $huge[$kb]['surplus'] += (int)($f[5] ?? 0);
      $huge[$kb]['nodes'][(int)$f[1]] = ['total' => (int)$f[3], 'free' => (int)$f[4]];
    }
  }
  ksort($huge);
  $nodeOf = [];
  foreach ($nodes as $n) foreach ($n['cpus'] as $c) $nodeOf[$c] = $n['node'];
  // L3 domains per package, in CPU order.
  $l3 = [];
  foreach ($cpus as $c => $t) {
    $pkg = (int)($t['physical_package_id'] ?? 0);
    $key = $pkg . '|' . ($t['l3_id'] ?? ($t['l3_shared_cpu_list'] ?? 'x'));
    $l3[$key] ??= ['package' => $pkg, 'id' => $t['l3_id'] ?? '', 'size' => $t['l3_size'] ?? '', 'cpus' => [], 'cores' => []];
    $l3[$key]['cpus'][] = $c;
    $l3[$key]['cores'][$t['core_id'] ?? $c] = true;
  }
  $family = isset($info['cpu family']) ? (int)$info['cpu family'] : null;
  $amd = ($info['vendor_id'] ?? '') === 'AuthenticAMD';
  // Zen/Zen+/Zen 2 (family 17h) have two CCXs (two L3s) per CCD; Zen 3, 4 and 5 one. (Zen 5c dense parts are the
  // exception; the CCD numbering is labelled as inferred.)
  $ccxPerCcd = $amd && $family === 0x17 ? 2 : 1;
  $packages = [];
  foreach ($l3 as $d) $packages[$d['package']][] = $d;
  $ccds = [];
  foreach ($packages as $pkg => $doms) foreach (array_values($doms) as $i => $d) {
    $n = intdiv($i, $ccxPerCcd);
    $ccds[$pkg][$n] ??= ['package' => $pkg, 'ccd' => $n, 'ccx' => [], 'cpus' => []];
    $d['cores'] = count($d['cores']);
    $d['index'] = $i;
    $d['nodes'] = array_values(array_unique(array_map(fn($c) => $nodeOf[$c] ?? 0, $d['cpus'])));
    $ccds[$pkg][$n]['ccx'][] = $d;
    $ccds[$pkg][$n]['cpus'] = array_merge($ccds[$pkg][$n]['cpus'], $d['cpus']);
  }
  $sockets = max(1, count($packages));
  $nn = count($nodes);
  $nl3 = count($l3);
  if (!$amd) $nps = $nn > $sockets ? ($nn / $sockets) . ' NUMA nodes per socket (sub-NUMA clustering)' : 'one NUMA node per socket';
  elseif ($nn > $sockets && $nn === $nl3) $nps = 'L3 as NUMA (each L3/CCX is a NUMA node)';
  elseif ($nn >= $sockets) $nps = 'NPS' . max(1, intdiv($nn, $sockets)) . ($sockets > 1 && $nn === 1 ? ' (NPS0: one node for all sockets)' : '');
  else $nps = '';
  $l3of = [];
  foreach ($ccds as $pkg => $list) foreach ($list as $ccd) foreach ($ccd['ccx'] as $x) foreach ($x['cpus'] as $c) $l3of[$c] = ['package' => $pkg, 'ccd' => $ccd['ccd'], 'ccx' => $x['index']];
  $m['cpu'] = [
    'model' => $info['model name'] ?? '', 'vendor' => $info['vendor_id'] ?? '', 'family' => $family, 'model_id' => isset($info['model']) ? (int)$info['model'] : null,
    'count' => count($cpus), 'cores' => count(array_unique(array_map(fn($t) => ($t['physical_package_id'] ?? 0) . '|' . ($t['core_id'] ?? ''), $cpus))),
    'sockets' => $sockets, 'nodes' => $nodes, 'node_of' => $nodeOf, 'nps' => $nps, 'ccds' => $ccds, 'ccx_per_ccd' => $ccxPerCcd, 'l3_of' => $l3of,
    'cache' => $cache, 'isolated' => $isolated, 'iso_flags' => $isoFlags, 'nohz_full' => pci_cpus($sets['nohz_full'] ?? ''),
    'siblings' => array_map(fn($t) => $t['core_id'] ?? null, $cpus),
  ];
  $m['huge'] = $huge;
  $mi = [];
  foreach (pci_lines("$dir/meminfo.txt") as $l) if (preg_match('/^(\w+):\s+(\d+)/', $l, $mm)) $mi[$mm[1]] = (int)$mm[2];
  $m['meminfo'] = $mi;
}

// PCI functions: lspci + sysfs, the tree, links, IOMMU groups, IRQs.
function pci_devices(string $dir, array &$m): void {
  $ls = pci_parse_lspci((string)@file_get_contents("$dir/lspci.txt"));
  $attr = pci_parse_attrs(pci_lines("$dir/pciattr.txt"));
  $slots = [];
  foreach (pci_lines("$dir/slots.txt") as $l) if (preg_match('#/slots/([^/]+)/address:([0-9a-f:]+)#', $l, $mm)) $slots[$mm[2]] = $mm[1];
  $vfioBoot = [];
  foreach (pci_lines("$dir/vfio-pci.cfg") as $l) if (preg_match_all('/([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7])/i', $l, $mm)) foreach ($mm[1] as $b) $vfioBoot[strtolower($b)] = true;
  $irqCpu = []; $irqName = [];
  foreach (pci_lines("$dir/irqaff.txt") as $l) if (preg_match('#/proc/irq/(\d+)/effective_affinity_list:(.*)$#', $l, $mm)) $irqCpu[(int)$mm[1]] = pci_cpus($mm[2]);
  foreach (pci_lines("$dir/irqnames.txt") as $l) { [$n, $name] = array_pad(explode('|', $l, 2), 2, ''); if (ctype_digit($n)) $irqName[(int)$n] = $name; }
  $re = '[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]';
  $links = []; $msi = [];
  foreach (pci_lines("$dir/pcilinks.txt") as $l)
    if (preg_match("#/($re)/*\|(driver|iommu_group|physfn)\|(.*)$#", $l, $mm)) $links[$mm[1]][$mm[2]] = basename($mm[3]);
  foreach (pci_lines("$dir/msi.txt") as $l) if (preg_match("#/($re)/msi_irqs/*\|(\d+)$#", $l, $mm)) $msi[$mm[1]][] = (int)$mm[2];
  $dev = [];
  foreach (pci_lines("$dir/pcipaths.txt") as $l) {
    if (!preg_match("#/([^/]+)/($re)$#", trim($l), $mm)) continue;
    [, $parent, $bdf] = $mm;
    $drv = $links[$bdf]['driver'] ?? ''; $grp = $links[$bdf]['iommu_group'] ?? ''; $pf = $links[$bdf]['physfn'] ?? '';
    $irqs = implode(' ', $msi[$bdf] ?? []);
    $a = $attr[$bdf] ?? [];
    $l2 = $ls[$bdf] ?? ['name' => '', 'classname' => '', 'subsystem' => '', 'slot' => '', 'type' => '', 'lnkcap' => null, 'lnksta' => null, 'aspm_cap' => '', 'aspm' => '',
                        'dlactive' => null, 'rebar' => [], 'modules' => '', 'driver' => '', 'lane_err' => '', 'acs' => null, 'rev' => ''];
    $gts = fn($s) => preg_match('/([\d.]+)\s*GT\/s/', (string)$s, $mm) ? (float)$mm[1] : null;
    $int = fn($s) => is_numeric($s ?? null) ? (int)$s : null;
    $irqList = array_map('intval', preg_split('/\s+/', trim($irqs), -1, PREG_SPLIT_NO_EMPTY));
    if (!$irqList && ($legacy = $int($a['irq'] ?? null)) && $legacy > 0 && isset($irqName[$legacy])) $irqList = [$legacy];
    $aer = [];
    foreach (['cor' => 'aer_dev_correctable', 'nonfatal' => 'aer_dev_nonfatal', 'fatal' => 'aer_dev_fatal'] as $k => $f)
      if (isset($a[$f])) $aer[$k] = ['total' => $a[$f]['TOTAL_ERR_' . strtoupper($k === 'cor' ? 'COR' : ($k === 'nonfatal' ? 'NONFATAL' : 'FATAL'))] ?? array_sum($a[$f]),
                                     'detail' => array_filter($a[$f], fn($v, $n) => $v > 0 && !str_starts_with($n, 'TOTAL_'), ARRAY_FILTER_USE_BOTH)];
    $class = $a['class'] ?? '';
    $dev[$bdf] = [
      'bdf' => $bdf, 'name' => $l2['name'], 'short' => pci_short_name($l2['name']), 'classname' => $l2['classname'], 'class' => $class,
      'vendor_id' => $a['vendor'] ?? '', 'device_id' => $a['device'] ?? '', 'subsystem' => $l2['subsystem'], 'rev' => $l2['rev'],
      'parent' => preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $parent) ? $parent : null,
      'root' => preg_match('/^pci([0-9a-f]{4}:[0-9a-f]{2})$/', $parent, $mm) ? $mm[1] : null, 'children' => [],
      'driver' => $drv !== '' ? $drv : $l2['driver'], 'modules' => $l2['modules'], 'group' => $grp === '' ? null : (int)$grp, 'physfn' => $pf ?: null, 'vfs' => [],
      'numa' => $int($a['numa_node'] ?? null), 'local_cpus' => $a['local_cpulist'] ?? '',
      'cur_speed' => $gts($a['current_link_speed'] ?? ''), 'cur_width' => $int($a['current_link_width'] ?? null),
      'max_speed' => $gts($a['max_link_speed'] ?? ''), 'max_width' => $int($a['max_link_width'] ?? null),
      'type' => $l2['type'], 'slot' => $l2['slot'] !== '' ? $l2['slot'] : ($slots[substr($bdf, 0, 10)] ?? ''), 'aspm' => $l2['aspm'], 'aspm_cap' => $l2['aspm_cap'],
      'dlactive' => $l2['dlactive'], 'rebar' => $l2['rebar'], 'lane_err' => $l2['lane_err'], 'power' => $a['power_state'] ?? '',
      'sriov' => isset($a['sriov_totalvfs']) && (int)$a['sriov_totalvfs'] > 0 ? ['total' => (int)$a['sriov_totalvfs'], 'num' => (int)($a['sriov_numvfs'] ?? 0)] : null,
      'aer' => $aer, 'irqs' => $irqList, 'irq_cpus' => pci_cpus(implode(',', array_map(fn($i) => pci_cpulist($irqCpu[$i] ?? []), $irqList))),
      'vfio_boot' => isset($vfioBoot[$bdf]), 'vms' => [], 'link' => null, 'role' => '',
    ];
  }
  ksort($dev, SORT_STRING);
  foreach ($dev as $b => $d) {
    if ($d['parent'] && isset($dev[$d['parent']])) $dev[$d['parent']]['children'][] = $b;
    if ($d['physfn'] && isset($dev[$d['physfn']])) $dev[$d['physfn']]['vfs'][] = $b;
  }
  // Roles: host bridge, root port, switch upstream/downstream port, bridge, endpoint.
  foreach ($dev as $b => &$d) {
    $isBridge = str_starts_with($d['class'], '0x0604') || str_starts_with($d['class'], '0x0609');
    $d['role'] = match (true) {
      $d['type'] === 'Root Port' => 'root port',
      $d['type'] === 'Upstream Port' => 'switch upstream',
      $d['type'] === 'Downstream Port' => 'switch port',
      str_starts_with($d['class'], '0x0600') => 'host bridge',
      $isBridge => 'bridge',
      default => 'endpoint',
    };
    // AMD's "Dummy Host Bridge" placeholders and data fabric functions carry nothing.
    $d['filler'] = $d['role'] === 'host bridge' && !$d['children'] && $d['root'] !== null && substr($b, -4) !== '00.0';
  }
  unset($d);
  // Links: from a root port or a switch's downstream port to what is below it, judged once, on the lowest function.
  // (A switch's upstream port and its downstream ports sit on the switch's internal bus: no link between them.)
  foreach ($dev as $b => &$d) {
    $p = $d['parent'] !== null ? ($dev[$d['parent']] ?? null) : null;
    if (!$p || !in_array($p['role'], ['root port', 'switch port'], true) || $d['max_speed'] === null || $p['max_speed'] === null) continue;
    if ($p['children'][0] !== $b) { $d['link'] = ['shared' => $p['children'][0]]; continue; }
    $expSpeed = min($d['max_speed'], $p['max_speed']);
    $expWidth = min((int)$d['max_width'], (int)$p['max_width']);
    $cs = $d['cur_speed'] ?? $p['cur_speed']; $cw = $d['cur_width'] ?? $p['cur_width'];
    $d['link'] = ['port' => $p['bdf'], 'cur_speed' => $cs, 'cur_width' => $cw, 'dev_speed' => $d['max_speed'], 'dev_width' => $d['max_width'],
                  'port_speed' => $p['max_speed'], 'port_width' => $p['max_width'], 'exp_speed' => $expSpeed, 'exp_width' => $expWidth,
                  'slow' => $cs !== null && $cs + 0.01 < $expSpeed, 'narrow' => $cw !== null && $cw < $expWidth,
                  'gbs' => pci_gbs($cs, $cw), 'exp_gbs' => pci_gbs($expSpeed, $expWidth)];
  }
  unset($d);
  // Roots (host bridges): one per PCI domain:bus the kernel enumerated.
  $roots = [];
  foreach ($dev as $b => $d) if ($d['root'] !== null) {
    $roots[$d['root']] ??= ['id' => $d['root'], 'children' => [], 'iommu' => null, 'name' => '', 'numa' => $d['numa']];
    $roots[$d['root']]['children'][] = $b;
    if (str_starts_with($d['class'], '0x0806')) $roots[$d['root']]['iommu'] = $b;
    if (substr($b, -4) === '00.0' && $roots[$d['root']]['name'] === '') $roots[$d['root']]['name'] = $d['short'];
  }
  ksort($roots);
  $groups = [];
  foreach ($dev as $b => $d) if ($d['group'] !== null) $groups[$d['group']][] = $b;
  ksort($groups);
  $m['devices'] = $dev;
  $m['roots'] = $roots;
  $m['groups'] = $groups;
}

// Memory: DIMMs per channel (dmidecode), EDAC counters mapped to channels where that is unambiguous.
function pci_memory(string $dir, array &$m): void {
  $dmi = pci_parse_dmi((string)@file_get_contents("$dir/dmidecode.txt"));
  $dimms = []; $array = null; $procs = [];
  foreach ($dmi as $r) {
    if ($r['type'] === 17) $dimms[] = pci_dimm($r['f']);
    elseif ($r['type'] === 16 && ($r['f']['Use'] ?? '') === 'System Memory') $array = $r['f'];
    elseif ($r['type'] === 4 && ($r['f']['Status'] ?? '') !== 'Unpopulated') $procs[] = $r['f'];
  }
  $chans = [];
  foreach ($dimms as $x) {
    $k = $x['socket'] . '|' . $x['channel'];
    $chans[$k] ??= ['key' => $k, 'socket' => $x['socket'], 'channel' => $x['channel'], 'known' => $x['channel_known'], 'dimms' => [], 'bytes' => 0, 'populated' => 0, 'edac' => null];
    $chans[$k]['dimms'][] = $x;
    if ($x['present']) { $chans[$k]['populated']++; $chans[$k]['bytes'] += (int)$x['bytes']; }
  }
  uksort($chans, 'strnatcmp');
  $edac = pci_parse_edac(pci_lines("$dir/edac.txt"));
  // EDAC numbers channels (UMCs) 0..n per controller; the board labels them A..H. They are paired in order when the
  // populated channels on both sides match in number and size, which is noted as an assumption on the page.
  $popChans = array_values(array_filter($chans, fn($c) => $c['populated'] > 0));
  $edacChans = [];
  foreach ($edac as $mc) foreach ($mc['channels'] as $c) if ($c['size_mb'] > 0) $edacChans[] = ['mc' => $mc['mc']] + $c;
  $mapped = false;
  $known = $chans && !array_filter($chans, fn($c) => !$c['known']);
  if ($known && $popChans && count($popChans) === count($edacChans)) {
    $mapped = true;
    foreach ($popChans as $i => $c) if (abs($c['bytes'] / 1048576 - $edacChans[$i]['size_mb']) > 1) $mapped = false;
    if ($mapped) foreach ($popChans as $i => $c) $chans[$c['key']]['edac'] = $edacChans[$i];
  }
  $m['mem'] = ['channels' => $chans, 'dimms' => $dimms, 'array' => $array, 'procs' => $procs, 'edac' => $edac, 'edac_mapped' => $mapped,
               'total' => array_sum(array_column($chans, 'bytes'))];
}

// ---------------------------------------------------------------------------------------------------------------
function pci_dev_label(array $d): string {
  return $d['bdf'] . ($d['short'] !== '' ? ' (' . preg_replace('/\s*\[[0-9a-f]{4}(:[0-9a-f]{4})?\]/', '', $d['short']) . ')' : '');
}

function pci_problems(array &$m): void {
  $add = function (string $level, string $text, string $who, string $key, string $value) use (&$m) {
    $m['problems'][] = ['level' => $level, 'text' => $text, 'who' => $who, 'key' => $key, 'value' => $value];
  };
  $dev = $m['devices'];
  $cpu = $m['cpu'];
  if (!$dev) $add('warn', 'No PCI device could be read (lspci and /sys/bus/pci gave nothing).', '', 'no-pci', '');

  // Links running below what both ends support.
  foreach ($dev as $b => $d) {
    $l = $d['link'];
    if (!$l || isset($l['shared']) || !($l['slow'] || $l['narrow'])) continue;
    $cur = pci_link_text($l['cur_speed'], $l['cur_width']);
    $exp = pci_link_text($l['exp_speed'], $l['exp_width']);
    $bw = $l['gbs'] && $l['exp_gbs'] ? sprintf(' (%.1f of %.1f GB/s)', $l['gbs'], $l['exp_gbs']) : '';
    $gpu = str_starts_with($d['class'], '0x03');
    $asleep = in_array($d['power'], ['D3hot', 'D3cold'], true);
    if (!$l['narrow'] && ($gpu || $asleep))
      $add('info', "Link to " . pci_dev_label($d) . " runs at $cur, below $exp$bw. " . ($gpu ? 'Graphics cards lower their link speed when idle; it rises under load.'
        : "The device is in {$d['power']} (power saving), so the speed may rise when it is used."), $b, "link|$b|speed", "$cur");
    else
      $add('warn', "Link to " . pci_dev_label($d) . " runs at $cur, but both ends support $exp$bw. "
        . ($l['narrow'] ? 'Fewer lanes trained: reseat the card, check the slot (some share lanes with M.2 or other slots) and the riser.'
          : 'It trained at a lower speed: check the BIOS PCIe speed setting for that slot, the riser or cable, and reseat the card.'),
        $b, "link|$b", "$cur");
  }

  // AER counters.
  foreach ($dev as $b => $d) foreach (['fatal' => 'crit', 'nonfatal' => 'warn', 'cor' => 'info'] as $k => $lvl) {
    $t = $d['aer'][$k]['total'] ?? 0;
    if ($t <= 0) continue;
    $what = ['fatal' => 'fatal', 'nonfatal' => 'non-fatal uncorrectable', 'cor' => 'corrected'][$k];
    $det = implode(', ', array_map(fn($n, $v) => "$n $v", array_keys($d['aer'][$k]['detail']), $d['aer'][$k]['detail']));
    $add($lvl, pci_dev_label($d) . ": $t $what PCIe error" . ($t === 1 ? '' : 's') . " since boot" . ($det ? " ($det)" : '') . '.'
      . ($k === 'cor' ? ' Corrected errors cost retries; a count that keeps growing points at the slot, riser or signal quality.' : ' Check the slot, riser and card; see the system log.'),
      $b, "aer|$b|$k", (string)$t);
  }

  // IRQs handled on isolated CPUs (host drivers only: vfio-pci interrupts belong to the VM).
  $iso = $cpu['isolated'] ?? [];
  if ($iso) {
    $hit = []; $n = 0;
    foreach ($dev as $b => $d) {
      if ($d['driver'] === 'vfio-pci' || !$d['irq_cpus']) continue;
      $on = array_intersect($d['irq_cpus'], $iso);
      if ($on) { $hit[] = $b; $n += count($on); }
    }
    if ($hit) {
      $byDrv = array_count_values(array_map(fn($b) => $dev[$b]['driver'] ?: $b, $hit));
      $names = array_map(fn($k, $v) => $v > 1 ? "$k x$v" : $k, array_keys($byDrv), $byDrv);
      $managed = in_array('managed_irq', $cpu['iso_flags'], true);
      $add('info', count($hit) . ' device' . (count($hit) === 1 ? '' : 's') . ' (' . implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? ', ...' : '')
        . ') handle interrupts on isolated CPUs ' . pci_cpulist($iso) . '. '
        . ($managed ? 'isolcpus has managed_irq, so these are queues with no other CPU to go to.'
          : 'Per-queue (managed) interrupts of NVMe and network drivers are spread over all CPUs, isolated ones included, unless isolcpus also has the managed_irq flag (e.g. isolcpus=managed_irq,domain,' . pci_cpulist($iso) . ').'),
        'cpu', 'irq-isolated', implode(',', $hit));
    }
  }

  // NUMA placement (only meaningful with more than one node).
  $nodes = $cpu['nodes'] ?? [];
  $nodeOf = $cpu['node_of'] ?? [];
  if (count($nodes) > 1) {
    foreach ($dev as $b => $d) {
      if ($d['numa'] === null || $d['numa'] < 0 || !$d['irq_cpus'] || $d['driver'] === 'vfio-pci') continue;
      $far = array_filter($d['irq_cpus'], fn($c) => ($nodeOf[$c] ?? $d['numa']) !== $d['numa']);
      if (count($far) === count($d['irq_cpus']))
        $add('info', pci_dev_label($d) . " is on NUMA node {$d['numa']}, but all its interrupts are handled on node(s) "
          . implode(',', array_unique(array_map(fn($c) => $nodeOf[$c], $far))) . ' (CPUs ' . pci_cpulist($far) . ').', $b, "numa-irq|$b", pci_cpulist($far));
    }
  }

  // VMs: passthrough devices present and bound to vfio-pci, groups, NUMA/CCD placement, pinning overlaps, hugepages.
  $pinnedBy = [];
  // IOMMU groups held by running VMs: VFIO hands a whole group to one VM, so no other VM can take any of it.
  $groupOwner = [];
  foreach ($m['vms'] as $name => $vm) if ($vm['running']) foreach ($vm['hostdevs'] as $hb)
    if (isset($dev[$hb]) && $dev[$hb]['group'] !== null) $groupOwner[$dev[$hb]['group']] = $name;
  foreach ($m['vms'] as $name => $vm) {
    $pins = [];
    foreach ($vm['pins'] as $set) $pins = array_merge($pins, pci_cpus($set));
    $pins = array_values(array_unique($pins));
    foreach ($vm['hostdevs'] as $hb) {
      if (!isset($dev[$hb])) { $add('warn', "VM \"$name\" passes through $hb, which is not in this system (the card was moved or the PCI numbering changed). The VM will not start until its passthrough list is fixed.", $hb, "vm-missing|$name|$hb", ''); continue; }
      $d = $dev[$hb];
      $m['devices'][$hb]['vms'][] = $name;
      if ($vm['running'] && $d['driver'] !== 'vfio-pci')
        $add('warn', "VM \"$name\" is running, but its passthrough device " . pci_dev_label($d) . ' is bound to ' . ($d['driver'] ?: 'no driver') . ', not vfio-pci.', $hb, "vm-driver|$name|$hb", $d['driver']);
      $owner = $d['group'] !== null ? ($groupOwner[$d['group']] ?? null) : null;
      if ($owner !== null && $owner !== $name)
        $add('warn', "VM \"$name\" passes through " . pci_dev_label($d) . " in IOMMU group {$d['group']}, which the running VM \"$owner\" holds. "
          . "It cannot start while \"$owner\" runs: a group goes to one VM.", $hb, "vm-group-owner|$name|$hb", $owner);
      // Other endpoints in the same IOMMU group must go to the VM as well, or be bound to vfio-pci (or pci-stub), or
      // have no driver at all: VFIO accepts unbound devices in a group.
      foreach ($m['groups'][$d['group']] ?? [] as $o) {
        $od = $dev[$o];
        if ($o === $hb || in_array($o, $vm['hostdevs'], true) || in_array($od['role'], ['root port', 'switch port', 'switch upstream', 'bridge', 'host bridge'], true) || in_array($od['driver'], ['vfio-pci', 'pci-stub', ''], true)) continue;
        $add('warn', "VM \"$name\": " . pci_dev_label($d) . " shares IOMMU group {$d['group']} with " . pci_dev_label($od) . ', which is not passed through and is bound to the host driver '
          . $od['driver'] . '. Pass it through as well or bind it to vfio-pci, or the VM cannot start.', $hb, "vm-group|$name|$hb|$o", $od['driver']);
      }
      if (count($nodes) > 1 && $d['numa'] !== null && $d['numa'] >= 0 && $pins) {
        $vmNodes = array_unique(array_map(fn($c) => $nodeOf[$c] ?? -1, $pins));
        if (!in_array($d['numa'], $vmNodes, true))
          $add('warn', "VM \"$name\" passes through " . pci_dev_label($d) . " on NUMA node {$d['numa']}, but its vCPUs are pinned to node(s) " . implode(',', $vmNodes)
            . '. Every DMA and interrupt crosses the Infinity Fabric; pin the VM to node ' . $d['numa'] . ' CPUs.', $hb, "vm-numa|$name|$hb", implode(',', $vmNodes));
      }
    }
    if (!$pins) continue;
    $l3s = array_unique(array_map(fn($c) => isset($cpu['l3_of'][$c]) ? $cpu['l3_of'][$c]['package'] . '|' . $cpu['l3_of'][$c]['ccx'] : '?', $pins));
    if (count($l3s) > 1)
      $add('info', "VM \"$name\" has its vCPUs on " . count($l3s) . ' L3 caches (CPUs ' . pci_cpulist($pins) . '). Threads on different CCDs/CCXs do not share L3, which costs latency in games and audio; one CCD is usually better.',
        'cpu', "vm-l3|$name", pci_cpulist($pins));
    if (count($nodes) > 1) {
      $vmNodes = array_unique(array_map(fn($c) => $nodeOf[$c] ?? -1, $pins));
      if (count($vmNodes) > 1) $add('info', "VM \"$name\" has vCPUs on NUMA nodes " . implode(',', $vmNodes) . '.', 'cpu', "vm-nodes|$name", implode(',', $vmNodes));
    }
    if ($vm['running']) foreach ($pins as $c) $pinnedBy[$c][] = $name;
  }
  $shared = [];
  foreach ($pinnedBy as $c => $names) if (count($names) > 1) $shared[implode(' and ', array_map(fn($n) => "\"$n\"", $names))][] = $c;
  foreach ($shared as $who => $cs) $add('info', "Running VMs $who have vCPUs pinned to the same CPUs " . pci_cpulist($cs) . '.', 'cpu', "vm-overlap|$who", pci_cpulist($cs));

  // Hugepages for VMs that are backed by them.
  foreach ($m['vms'] as $name => $vm) {
    if ($vm['huge_kib'] === null || !$vm['mem_kib']) continue;
    $size = $vm['huge_kib'] ?: (int)($m['meminfo']['Hugepagesize'] ?? 2048);
    $need = (int)ceil($vm['mem_kib'] / $size);
    $h = $m['huge'][$size] ?? ['total' => 0, 'free' => 0, 'nodes' => []];
    $sz = pci_bytes_text($size * 1024);
    // A strict (or restrictive) numatune can only take pages from its nodes.
    $where = '';
    if ($vm['numa'] !== '' && in_array($vm['numa_mode'], ['strict', 'restrictive'], true) && count($h['nodes'] ?? []) > 1) {
      $ns = pci_cpus($vm['numa']);
      $h = ['total' => array_sum(array_map(fn($n) => $h['nodes'][$n]['total'] ?? 0, $ns)), 'free' => array_sum(array_map(fn($n) => $h['nodes'][$n]['free'] ?? 0, $ns))];
      $where = ' on NUMA node' . (count($ns) === 1 ? ' ' : 's ') . $vm['numa'] . " (numatune {$vm['numa_mode']})";
    }
    if ($h['total'] < $need)
      $add('warn', "VM \"$name\" uses $sz hugepages for " . pci_bytes_text($vm['mem_kib'] * 1024) . " ($need pages), but only {$h['total']} are reserved$where. It will not start.", 'mem', "huge|$name", "{$h['total']}");
    elseif (!$vm['running'] && $h['free'] < $need)
      $add('warn', "VM \"$name\" needs $need free $sz hugepages to start, but only {$h['free']} of {$h['total']}$where are free (other VMs hold the rest).", 'mem', "huge-free|$name", "{$h['free']}");
  }

  // Memory: ECC counters, population, speed.
  $mem = $m['mem'];
  foreach ($mem['edac'] as $mc) {
    $label = fn($c) => pci_mem_edac_label($mem, $mc['mc'], $c['channel']);
    foreach ($mc['channels'] as $c) {
      if ($c['ue'] > 0) $add('crit', "Memory: {$c['ue']} uncorrectable ECC error" . ($c['ue'] === 1 ? '' : 's') . " on {$label($c)}. The pairing of EDAC channels with board slots is a guess: confirm the DIMM in the board manual or the BMC/BIOS event log before reseating or replacing it.", 'mem', "ecc-ue|{$mc['mc']}|{$c['channel']}", (string)$c['ue']);
      if ($c['ce'] > 0) $add('warn', "Memory: {$c['ce']} corrected ECC error" . ($c['ce'] === 1 ? '' : 's') . " on {$label($c)} since " . ($mc['since'] !== null ? 'the counters were reset (' . pci_duration($mc['since']) . ' ago)' : 'boot')
        . '. A count that keeps growing means the DIMM is failing.', 'mem', "ecc-ce|{$mc['mc']}|{$c['channel']}", (string)$c['ce']);
    }
    if ($mc['ue_noinfo'] > 0) $add('crit', "Memory controller {$mc['mc']}: {$mc['ue_noinfo']} uncorrectable ECC errors without a location.", 'mem', "ecc-ue-noinfo|{$mc['mc']}", (string)$mc['ue_noinfo']);
    if ($mc['ce_noinfo'] > 0) $add('warn', "Memory controller {$mc['mc']}: {$mc['ce_noinfo']} corrected ECC errors without a location.", 'mem', "ecc-ce-noinfo|{$mc['mc']}", (string)$mc['ce_noinfo']);
    // Errors the controller counted that no channel (or the noinfo counters) accounts for.
    $ueLeft = $mc['ue'] - array_sum(array_column($mc['channels'], 'ue')) - $mc['ue_noinfo'];
    $ceLeft = $mc['ce'] - array_sum(array_column($mc['channels'], 'ce')) - $mc['ce_noinfo'];
    if ($ueLeft > 0) $add('crit', "Memory controller {$mc['mc']} ({$mc['name']}): $ueLeft uncorrectable ECC error" . ($ueLeft === 1 ? '' : 's') . ' not attributed to a channel. Check the BMC/BIOS event log.', 'mem', "ecc-ue-mc|{$mc['mc']}", (string)$ueLeft);
    if ($ceLeft > 0) $add('warn', "Memory controller {$mc['mc']} ({$mc['name']}): $ceLeft corrected ECC error" . ($ceLeft === 1 ? '' : 's') . ' not attributed to a channel. A count that keeps growing means a DIMM is failing.', 'mem', "ecc-ce-mc|{$mc['mc']}", (string)$ceLeft);
  }
  $ecc = preg_match('/ECC/i', $mem['array']['Error Correction Type'] ?? '') && !preg_match('/^None/i', $mem['array']['Error Correction Type'] ?? '');
  if (!$mem['edac'] && $ecc) $add('info', 'Memory: the board reports ECC memory, but no EDAC driver is loaded (/sys/devices/system/edac/mc is empty), so corrected and uncorrectable errors are not counted here.', 'mem', 'edac-missing', '');
  $chans = $mem['channels'];
  if ($chans) {
    $bySock = [];
    foreach ($chans as $c) $bySock[$c['socket']][] = $c;
    foreach ($bySock as $s => $cs) {
      // Without a channel in the DMI labels (e.g. "DIMM0".."DIMM3"), slots cannot be grouped into channels: two
      // DIMMs in four slots may well fill both channels. No population or balance conclusions then.
      if (array_filter($cs, fn($c) => !$c['known'])) continue;
      $pop = array_filter($cs, fn($c) => $c['populated'] > 0);
      $tag = count($bySock) > 1 ? "socket $s: " : '';
      if ($pop && count($pop) < count($cs))
        $add('warn', "Memory {$tag}only " . count($pop) . ' of ' . count($cs) . ' channels have a DIMM (' . implode(', ', array_map(fn($c) => $c['channel'], array_diff_key($cs, $pop)))
          . ' empty), so memory bandwidth is ' . count($pop) . '/' . count($cs) . ' of what the CPU can do.', 'mem', "mem-channels|$s", count($pop) . '/' . count($cs));
      $sizes = array_unique(array_map(fn($c) => $c['bytes'], $pop));
      if (count($sizes) > 1)
        $add('warn', "Memory {$tag}channels hold different amounts (" . implode(', ', array_map(fn($c) => "{$c['channel']} " . pci_bytes_text($c['bytes']), $pop))
          . '). Unbalanced channels interleave unevenly and lose bandwidth.', 'mem', "mem-balance|$s", implode(',', $sizes));
    }
    $present = array_filter($mem['dimms'], fn($x) => $x['present']);
    $slow = array_filter($present, fn($x) => $x['rated'] && $x['configured'] && $x['configured'] < $x['rated']);
    if ($slow) {
      $pairs = array_unique(array_map(fn($x) => "{$x['configured']} of {$x['rated']}", $slow));
      $add('info', 'Memory runs at ' . implode(', ', $pairs) . ' MT/s rated (' . count($slow) . ' of ' . count($present) . ' DIMMs). Two DIMMs per channel, mixed modules or the BIOS setting lower it.',
        'mem', 'mem-speed', implode(',', $pairs));
    }
    $parts = array_unique(array_filter(array_map(fn($x) => $x['part'], $present)));
    if (count($parts) > 1) $add('info', 'Memory: DIMMs of ' . count($parts) . ' different part numbers (' . implode(', ', $parts) . '). Mixed modules run at the slowest one\'s timings.', 'mem', 'mem-parts', implode(',', $parts));
  }
}

// EDAC channel name: the board's channel letter when mapped, else "controller n channel m".
function pci_mem_edac_label(array $mem, int $mc, int $ch): string {
  foreach ($mem['channels'] as $c) if ($c['edac'] && $c['edac']['mc'] === $mc && $c['edac']['channel'] === $ch) {
    $locs = implode('/', array_map(fn($x) => $x['locator'], array_filter($c['dimms'], fn($x) => $x['present'])));
    return "EDAC mc$mc channel $ch (probably board channel {$c['channel']}" . ($locs !== '' ? ", $locs" : '') . ')';
  }
  return "memory controller $mc channel $ch";
}

function pci_duration(int $s): string {
  if ($s < 120) return "$s s";
  if ($s < 7200) return round($s / 60) . ' min';
  if ($s < 172800) return round($s / 3600) . ' h';
  return round($s / 86400) . ' days';
}

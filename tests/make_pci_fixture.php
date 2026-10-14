<?php
/* Copies a PCI Topology collection (/var/local/storage-topology/pci/current) into a test fixture, anonymised the
 * same way as the diagnostics archive (include/anonymise.php): serials, asset tags, MACs, GUIDs, PCIe serial numbers
 * and VPD, and the unraiduuid on the kernel command line.
 *   php tests/make_pci_fixture.php <collection dir> <fixture dir> [hostname...]
 */
require_once dirname(__DIR__) . '/src/usr/local/emhttp/plugins/storage-topology/include/anonymise.php';
[$from, $to] = [$argv[1], $argv[2]];
@mkdir($to, 0755, true);
$st = st_anon_new(array_slice($argv, 3), 0x1234);
$texts = [];
foreach (glob("$from/*") as $f) if (is_file($f)) { $texts[basename($f)] = (string)file_get_contents($f); st_anon_scan($st, basename($f), $texts[basename($f)]); }
foreach ($texts as $n => $t) file_put_contents("$to/$n", st_anon_text($st, $n, $t));
fwrite(STDERR, count($texts) . " files, " . count($st['literals']) . " literals replaced\n");

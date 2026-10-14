<?PHP
/* Reduce the libvirt VM definitions to what the PCI Topology page needs (called by collect-pci.sh):
 *   php vms.php <config dir> <run dir>
 * One line per fact: vm|name|running|vcpus|memory KiB|hugepage KiB, pin|name|vcpu|cpuset, emu|name|cpuset,
 * numa|name|nodeset, hostdev|name|0000:bb:dd.f. A running VM's live definition (<run dir>/<name>.xml) is used
 * when there is one. Disks, networks, UUIDs, MACs, passwords and everything else in the XML are left out.
 */
require_once dirname(__DIR__) . '/include/pci.php';
[$conf, $rundir] = [$argv[1] ?? '/etc/libvirt/qemu', $argv[2] ?? '/var/run/libvirt/qemu'];
foreach (glob("$conf/*.xml") ?: [] as $f) {
  $name = basename($f, '.xml');
  $running = is_file("$rundir/$name.pid");
  $live = $running && is_file("$rundir/$name.xml") ? (string)@file_get_contents("$rundir/$name.xml") : '';
  $vm = pci_vm_parse($live !== '' ? $live : (string)@file_get_contents($f));
  if ($vm === null) continue;
  echo implode("\n", pci_vm_lines($vm, $running)), "\n";
}

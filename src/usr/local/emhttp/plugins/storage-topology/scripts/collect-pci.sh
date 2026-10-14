#!/bin/bash
# PCI Topology collector. Read-only: one `lspci -D -nn -vvv`, one `dmidecode -t 4,16,17`, and /sys and /proc
# (PCI devices, slots, CPU topology and caches, NUMA nodes and hugepages, EDAC memory controllers, IRQ affinity),
# /boot/config/vfio-pci.cfg and the libvirt VM definitions (reduced to names, pinning, memory and PCI passthrough
# by scripts/vms.php; nothing else of the XML is kept). It never writes to config space or sysfs.
# One run at a time (own lock); a finished run replaces the cache in one rename.
# Usage: collect-pci.sh [max_age_seconds]   - does nothing if the cache is younger than that.
CACHE=${ST_PCI_CACHE:-/var/local/storage-topology/pci}
LOCK=${ST_PCI_LOCK:-/var/run/storage-topology-pci.lock}
MAXAGE=${1:-0}
HERE=$(dirname "$(readlink -f "$0")")

fresh() { [ -f "$CACHE/current/done" ] && [ $(( $(date +%s) - $(stat -c %Y "$CACHE/current/done") )) -lt "$MAXAGE" ]; }

mkdir -p "$CACHE"
exec 9>"$LOCK"
if ! flock -n 9; then
  flock -w 60 9 || exit 75
  exit 0
fi
[ "$MAXAGE" -gt 0 ] && fresh && exit 0

for d in "$CACHE"/new.*; do
  [ -d "$d" ] && [[ ${d##*/} =~ ^new\.[0-9]+$ ]] && rm -rf -- "$d"
done
NEW="$CACHE/new.$$"
mkdir -p "$NEW"

# run <file> <timeout> <command...>: output to $NEW/<file>, one timing line per command.
run() {
  local name=$1 t=$2 s rc; shift 2
  s=$(date +%s%3N)
  { timeout -k 3 "$t" nice -n 10 "$@" >"$NEW/$name" 2>"$NEW/$name.err"; } 2>/dev/null; rc=$?
  [ $rc -gt 128 ] && [ $rc -ne 137 ] && echo "terminated by signal $((rc - 128))" >>"$NEW/$name.err"
  echo "$name|$rc|$(( $(date +%s%3N) - s ))" >>"$NEW/timings"
  [ -s "$NEW/$name.err" ] || rm -f "$NEW/$name.err"
  return $rc
}

run lspci.txt 20 lspci -D -nn -vvv
run dmidecode.txt 15 dmidecode -t 4,16,17

# PCI functions: attributes in one pass (grep -H prefixes each line with its file), then the links per device.
P=/sys/bus/pci/devices
grep -sH . $P/*/{vendor,device,subsystem_vendor,subsystem_device,class,numa_node,local_cpulist,current_link_speed,current_link_width,max_link_speed,max_link_width,power_state,sriov_totalvfs,sriov_numvfs,irq,aer_dev_correctable,aer_dev_nonfatal,aer_dev_fatal,link/l0s_aspm,link/l1_aspm,link/l1_1_aspm,link/l1_2_aspm,link/clkpm} >"$NEW/pciattr.txt"
# Links in three calls (a loop with a subshell per device takes seconds on a big system): canonical paths (the
# parent bridge), driver/iommu_group/physfn links, and MSI interrupt numbers.
readlink -f $P/* >"$NEW/pcipaths.txt"
find $P/*/ -maxdepth 1 \( -name driver -o -name iommu_group -o -name physfn \) -printf '%h|%f|%l\n' >"$NEW/pcilinks.txt" 2>/dev/null
find $P/*/msi_irqs/ -mindepth 1 -maxdepth 1 -printf '%h|%f\n' >"$NEW/msi.txt" 2>/dev/null
grep -sH . /sys/bus/pci/slots/*/address >"$NEW/slots.txt"

# IRQs: effective CPU of each one and its name (the last column of /proc/interrupts).
grep -sH . /proc/irq/*/effective_affinity_list >"$NEW/irqaff.txt"
awk 'NR > 1 { sub(":", "", $1); print $1 "|" $NF }' /proc/interrupts >"$NEW/irqnames.txt" 2>/dev/null

# CPU topology, L3 domains, NUMA nodes, hugepages, kernel command line.
C=/sys/devices/system/cpu
grep -sH . $C/cpu[0-9]*/topology/{core_id,physical_package_id,die_id} $C/cpu[0-9]*/cache/index3/{id,size,shared_cpu_list} $C/cpu0/cache/index[0-2]/{level,type,size,shared_cpu_list} >"$NEW/cputopo.txt"
for f in isolated online nohz_full; do echo "$f|$(cat "$C/$f" 2>/dev/null)"; done >"$NEW/cpusets.txt"
awk '/^(vendor_id|cpu family|model|model name)[[:space:]]*:/ && !seen[$0]++' /proc/cpuinfo >"$NEW/cpuinfo.txt"
for n in /sys/devices/system/node/node[0-9]*; do
  [ -d "$n" ] || continue
  echo "node|${n##*node}|$(cat "$n/cpulist" 2>/dev/null)|$(awk '/MemTotal/ {print $4}' "$n/meminfo" 2>/dev/null)|$(cat "$n/distance" 2>/dev/null)"
  for h in "$n"/hugepages/hugepages-*; do
    [ -d "$h" ] && echo "huge|${n##*node}|${h##*-}|$(cat "$h/nr_hugepages")|$(cat "$h/free_hugepages")|$(cat "$h/surplus_hugepages" 2>/dev/null)"
  done
done >"$NEW/nodes.txt"
grep -E '^(MemTotal|HugePages_|Hugepagesize|Hugetlb)' /proc/meminfo >"$NEW/meminfo.txt"
cp /proc/cmdline "$NEW/cmdline.txt"

# EDAC (amd64_edac and others): controller totals and per-rank/DIMM counters.
E=/sys/devices/system/edac/mc
grep -sH . $E/mc*/{mc_name,size_mb,ce_count,ue_count,ce_noinfo_count,ue_noinfo_count,seconds_since_reset} \
  $E/mc*/{rank,dimm}*/{dimm_location,dimm_label,size,dimm_ce_count,dimm_ue_count,dimm_mem_type,dimm_edac_mode} >"$NEW/edac.txt"

# Devices Unraid binds to vfio-pci at boot, and the VMs (names, pinning, memory, passthrough only).
grep -s '^BIND=' /boot/config/vfio-pci.cfg | tr -d '\r' >"$NEW/vfio-pci.cfg"
run vms.txt 10 php "$HERE/vms.php" /etc/libvirt/qemu /var/run/libvirt/qemu

# Publication guard: without lspci and PCI sysfs there is nothing to show; keep the last good collection.
if [ ! -s "$NEW/pcipaths.txt" ] && [ -s "$CACHE/current/pcipaths.txt" ]; then
  echo "collect-pci: no PCI device could be read; keeping the previous collection" >&2
  rm -rf -- "$NEW"
  exit 3
fi

date +%s >"$NEW/done"
rm -rf "$CACHE/old"
[ -d "$CACHE/current" ] && mv "$CACHE/current" "$CACHE/old"
mv "$NEW" "$CACHE/current"
rm -rf "$CACHE/old"
exit 0

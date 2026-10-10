#!/bin/bash
# Storage Topology collector. Read-only: storcli "show" commands with nolog (if storcli is installed),
# sg_ses status pages (joined, or one at a time when --join fails), lsscsi, lsblk, the kernel's SAS (hosts, PHYs,
# ports, expanders, end devices), SCSI host and enclosure sysfs, and emhttp's disks.ini/devs.ini.
# One run at a time (flock); a finished run replaces the cache in one rename.
# Usage: collect.sh [max_age_seconds]   - does nothing if the cache is younger than that.
CACHE=/var/local/storage-topology
LOCK=/var/run/storage-topology.lock
MAXAGE=${1:-0}
TMO=60

fresh() { [ -f "$CACHE/current/done" ] && [ $(( $(date +%s) - $(stat -c %Y "$CACHE/current/done") )) -lt "$MAXAGE" ]; }

mkdir -p "$CACHE"
exec 9>"$LOCK"
if ! flock -n 9; then
  # Another run is collecting: wait for it rather than start a second storcli.
  flock -w 120 9 || exit 75
  exit 0
fi
[ "$MAXAGE" -gt 0 ] && fresh && exit 0

# Stale staging folders of earlier runs: only this collector's own (new.<pid>, a directory).
for d in "$CACHE"/new.*; do
  [ -d "$d" ] && [[ ${d##*/} =~ ^new\.[0-9]+$ ]] && rm -rf -- "$d"
done
NEW="$CACHE/new.$$"
mkdir -p "$NEW"
# Block device I/O counters at the start and end of the run (kernel counters: no drive is queried), for the
# shelf and controller throughput. Each snapshot starts with its time in milliseconds.
{ date +%s%3N; cat /proc/diskstats; } >"$NEW/diskstats.start" 2>/dev/null

# run <file> <timeout> <command...>: output to $NEW/<file>, one timing line per command.
run() {
  local name=$1 t=$2 s rc; shift 2
  s=$(date +%s%3N)
  # (Braces: a command that crashes is noted in its .err file rather than by bash on the collector's stderr.)
  { timeout -k 5 "$t" nice -n 10 "$@" >"$NEW/$name" 2>"$NEW/$name.err"; } 2>/dev/null; rc=$?
  [ $rc -gt 128 ] && [ $rc -ne 137 ] && echo "terminated by signal $((rc - 128))" >>"$NEW/$name.err"
  echo "$name|$rc|$(( $(date +%s%3N) - s ))" >>"$NEW/timings"
  [ -s "$NEW/$name.err" ] || rm -f "$NEW/$name.err"
  return $rc
}

STORCLI=
for b in /sbin/storcli /usr/sbin/storcli /usr/local/sbin/storcli /usr/bin/storcli /usr/local/bin/storcli \
         /sbin/storcli64 /usr/sbin/storcli64 /usr/local/sbin/storcli64 /opt/MegaRAID/storcli/storcli64; do
  [ -x "$b" ] && STORCLI=$b && break
done
echo "$STORCLI" >"$NEW/storcli.path"
[ -d /sys/module/megaraid_sas ] && touch "$NEW/megaraid_sas"

# storcli <file> <args...>: skipped after a storcli timeout, so a stalled controller does not get a queue of commands.
STALLED=0
storcli() {
  local name=$1 rc; shift
  [ $STALLED -eq 1 ] && return
  run "$name" $TMO "$STORCLI" "$@" J nolog; rc=$?
  [ $rc -eq 124 ] || [ $rc -eq 137 ] && STALLED=1
}

if [ -n "$STORCLI" ]; then
  storcli ctrl.json /call show all
  storcli phys.json /call/pall show
  storcli encl.json /call/eall show all
  # Per-drive detail talks to the drives. Skip it while any disk is spun down so this page never wakes one:
  # array and pool disks (disks.ini) and unassigned devices (devs.ini) alike.
  SPUN=$(cat /var/local/emhttp/disks.ini /var/local/emhttp/devs.ini 2>/dev/null | grep -c '^spundown="1"')
  # Drives not in any enclosure (e.g. direct-attached on tri-mode controllers) are only listed by /cx/sall.
  # Controllers without such drives answer "No drive found!", which the page ignores.
  if [ "${SPUN:-0}" -eq 0 ]; then
    storcli drives.json /call/eall/sall show all
    storcli drives_noencl.json /call/sall show all
  else
    echo "$SPUN" >"$NEW/drives.skipped"
    storcli drives.json /call/eall/sall show
    storcli drives_noencl.json /call/sall show
  fi
fi

# ses_join_ok <rc> <file>: sg_ses --json --join exited 0 and left an element list. PHP (always there on Unraid)
# checks the JSON; without it the file must at least hold the list and end with a closing brace.
# ("report timestamp: ... DID_SOFT_ERROR" on stderr, seen on some shelves, is harmless and not looked at.)
ses_join_ok() {
  [ "$1" -eq 0 ] && [ -s "$2" ] || return 1
  if command -v php >/dev/null; then
    php -n -r '$j = json_decode((string)file_get_contents($argv[1]), true); exit(is_array($j["join_of_diagnostic_pages"]["element_list"] ?? null) ? 0 : 1);' "$2" 2>/dev/null
  else
    grep -q '"element_list"' "$2" && [ "$(tr -d ' \t\r\n' <"$2" | tail -c 1)" = "}" ]
  fi
}

lsscsi -g >"$NEW/lsscsi.txt" 2>/dev/null
if command -v sg_ses >/dev/null; then
  sg_ses -V >"$NEW/sg_ses.version" 2>&1
  for sg in $(awk '$2=="enclosu"{print $NF}' "$NEW/lsscsi.txt"); do
    n=${sg##*/}
    run "ses_$n.json" 20 sg_ses --json --join "$sg"; jrc=$?
    # Simple enclosures (USB drive boxes, SGPIO bridges, some virtual SES) only answer the one-byte
    # "Short enclosure status" page, so --join fails; keep that byte as the enclosure's status.
    grep -ho 'only supports Short enclosure status[^,]*, status=0x[0-9a-fA-F]*' "$NEW/ses_$n.json.err" "$NEW/ses_$n.json" 2>/dev/null \
      | head -1 | grep -o 'status=0x[0-9a-fA-F]*' >"$NEW/ses_$n.short" 2>/dev/null
    if [ -s "$NEW/ses_$n.short" ]; then continue; fi
    rm -f "$NEW/ses_$n.short"
    # Configuration page: subenclosures (vendor, product, revision) and the type descriptor texts, which name the
    # elements of enclosures that have no element descriptor page (e.g. EMC KTN-STL3: "Power Supply B").
    run "sescfg_$n.json" 20 sg_ses --json -p 1 "$sg"
    # If --join failed or left no usable JSON, read the pages it joins one at a time; the page joins them
    # (include/topology.php, topo_ses_join). sg_ses 2.86 (sg3_utils 1.48) crashes in --json --join on enclosures
    # without an element descriptor page, e.g. an EMC KTN-STL3 (fixed in 2.88); its text --join and -p pages work.
    # Enclosure status (2) is needed; element descriptors (7) and additional element status (0Ah) are optional.
    # An sg_ses too old for --json would fail these the same way, so they are skipped then.
    if ! ses_join_ok "$jrc" "$NEW/ses_$n.json" && ! grep -qiE 'unrecognized option|invalid option|illegal option' "$NEW/ses_$n.json.err" 2>/dev/null; then
      run "sesstat_$n.json" 20 sg_ses --json -p 0x2 "$sg"
      run "sesdesc_$n.json" 20 sg_ses --json -p 0x7 "$sg"
      run "sesaes_$n.json" 20 sg_ses --json -p 0xa "$sg"
    fi
  done
fi
# Each SCSI host's driver (mpt3sas, megaraid_sas, ahci, usb-storage, uas, ...), so the page can tell USB enclosures apart.
for h in /sys/class/scsi_host/host*; do
  [ -d "$h" ] && echo "${h##*/}|$(cat "$h/proc_name" 2>/dev/null)"
done >"$NEW/scsi_hosts.txt" 2>/dev/null

# Kernel view, used when storcli is not installed (e.g. LSI HBAs in IT mode on mpt3sas):
# enclosure bays with their disks, and the HBA's own PHYs with link rates and what each port connects to.
for e in /sys/class/enclosure/*; do
  [ -d "$e" ] || continue
  for s in "$e"/*/; do
    [ -f "$s/status" ] || continue
    blk=$(ls "$s/device/block" 2>/dev/null | head -1)
    echo "${e##*/}|$(cat "$e/id" 2>/dev/null)|$(basename "$s")|$(cat "$s/slot" 2>/dev/null)|$(cat "$s/status" 2>/dev/null)|$(cat "$s/fault" 2>/dev/null)|$(cat "$s/locate" 2>/dev/null)|$blk"
  done
done >"$NEW/enclosure_sysfs.txt" 2>/dev/null
# PHYs: host PHYs (phy-H:N) to sas_phys.txt, expander PHYs (phy-H:E:N) to expander_phys.txt. Each with the
# sas_port it belongs to and what that port connects to (expander-* or end_device-* = its SAS address).
for p in /sys/class/sas_phy/phy-*; do
  n=${p##*/}
  port=$(ls -d /sys/class/sas_port/*/device/"$n" 2>/dev/null | head -1 | awk -F/ '{print $5}')
  att=
  if [ -n "$port" ]; then
    d=$(ls -d /sys/class/sas_port/"$port"/device/{expander,end_device}-* 2>/dev/null | head -1)
    [ -n "$d" ] && att="$(basename "$d")=$(cat /sys/class/sas_device/"$(basename "$d")"/sas_address 2>/dev/null)"
  fi
  neg=$(cat "$p/negotiated_linkrate" 2>/dev/null); max=$(cat "$p/maximum_linkrate" 2>/dev/null)
  hw=$(cat "$p/maximum_linkrate_hw" 2>/dev/null); sas=$(cat "$p/sas_address" 2>/dev/null)
  if [[ $n =~ ^phy-[0-9]+:[0-9]+$ ]]; then
    echo "$n|$neg|$max|$sas|$port|$att|$hw" >>"$NEW/sas_phys.txt"
  else
    echo "$n|$neg|$max|$hw|$sas|$port|$att" >>"$NEW/expander_phys.txt"
  fi
done 2>/dev/null
touch "$NEW/sas_phys.txt"
# Expanders: address, SMP identity (e.g. LSI SAS2X28) and the port they hang off.
for e in /sys/class/sas_expander/expander-*; do
  [ -d "$e" ] || continue
  n=${e##*/}
  d=$(readlink -f "$e/device")
  echo "$n|$(cat /sys/class/sas_device/"$n"/sas_address 2>/dev/null)|$(cat "$e/vendor_id" 2>/dev/null)|$(cat "$e/product_id" 2>/dev/null)|$(cat "$e/product_rev" 2>/dev/null)|$(basename "$(dirname "$d")")"
done >"$NEW/expanders.txt" 2>/dev/null
# End devices (disks and SES processors): address, protocol (ssp/sata), the port and PHYs they hang off
# (host PHYs = direct-attached, expander PHYs = behind an expander), SCSI address, type, vendor, model, block device.
for e in /sys/class/sas_device/end_device-*; do
  [ -d "$e" ] || continue
  n=${e##*/}
  d=$(readlink -f "$e/device")
  pp=$(dirname "$d")
  phys=$(ls -d "$pp"/phy-* 2>/dev/null | xargs -r -n1 basename | tr '\n' ',' | sed 's/,$//')
  sd=$(ls -d "$d"/target*/[0-9]*:[0-9]*:[0-9]*:[0-9]* 2>/dev/null | head -1)
  hctl=; typ=; ven=; mdl=; blk=
  if [ -n "$sd" ]; then
    hctl=${sd##*/}; typ=$(cat "$sd/type" 2>/dev/null); ven=$(cat "$sd/vendor" 2>/dev/null); mdl=$(cat "$sd/model" 2>/dev/null)
    blk=$(ls "$sd/block" 2>/dev/null | head -1)
  fi
  echo "$n|$(cat "$e/sas_address" 2>/dev/null)|$(cat "$e/target_port_protocols" 2>/dev/null)|$(basename "$pp")|$phys|$hctl|$typ|$ven|$mdl|$blk"
done >"$NEW/end_devices.txt" 2>/dev/null

# SAS hosts: driver, board name, firmware and PCI address (the host's parent device), which matches a host to its
# storcli controller.
for h in /sys/class/sas_host/host*; do
  [ -d "$h" ] || continue
  s=/sys/class/scsi_host/${h##*/}
  echo "${h##*/}|$(cat "$s/proc_name" 2>/dev/null)|$(cat "$s/board_name" 2>/dev/null)|$(cat "$s/version_fw" 2>/dev/null)|$(basename "$(dirname "$(readlink -f "$s/device")")")"
done >"$NEW/sas_hosts.txt" 2>/dev/null

lsblk -dJ -o NAME,SERIAL,WWN,TRAN,MODEL,SIZE >"$NEW/lsblk.json" 2>/dev/null
# Each disk's device identification page (VPD 83h) as the kernel cached it at scan time (reading it sends nothing
# to the drive): its SAS port addresses tie shelf bays to block devices even when storcli's per-drive detail is
# skipped because a disk is spun down.
for b in /sys/block/sd*; do
  f=$b/device/vpd_pg83
  [ -r "$f" ] && echo "${b##*/}|$(od -An -tx1 -v "$f" | tr -d ' \n')"
done >"$NEW/vpd83.txt" 2>/dev/null
cp /var/local/emhttp/disks.ini "$NEW/" 2>/dev/null
cp /var/local/emhttp/devs.ini "$NEW/" 2>/dev/null

# At least two seconds between the snapshots, so the rates mean something on a fast run.
s0=$(head -1 "$NEW/diskstats.start" 2>/dev/null); now=$(date +%s%3N)
[ -n "$s0" ] && [ $(( now - s0 )) -lt 2000 ] && sleep "$(awk -v d=$(( 2000 - (now - s0) )) 'BEGIN{printf "%.3f", d/1000}')"
{ date +%s%3N; cat /proc/diskstats; } >"$NEW/diskstats.end" 2>/dev/null
date +%s >"$NEW/done"
rm -rf "$CACHE/old"
[ -d "$CACHE/current" ] && mv "$CACHE/current" "$CACHE/old"
mv "$NEW" "$CACHE/current"
rm -rf "$CACHE/old"
exit 0

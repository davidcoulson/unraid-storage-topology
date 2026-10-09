#!/bin/bash
# Storage Topology collector. Read-only: storcli "show" commands with nolog (if storcli is installed),
# sg_ses status pages, lsscsi, lsblk, the kernel's SAS and enclosure sysfs, and emhttp's disks.ini/devs.ini.
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

rm -rf "$CACHE"/new.*
NEW="$CACHE/new.$$"
mkdir -p "$NEW"

# run <file> <timeout> <command...>: output to $NEW/<file>, one timing line per command.
run() {
  local name=$1 t=$2 s rc; shift 2
  s=$(date +%s%3N)
  timeout -k 5 "$t" nice -n 10 "$@" >"$NEW/$name" 2>"$NEW/$name.err"; rc=$?
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
  # Per-drive detail talks to the drives. Skip it while any disk is spun down so this page never wakes one.
  SPUN=$(grep -c '^spundown="1"' /var/local/emhttp/disks.ini 2>/dev/null)
  if [ "${SPUN:-0}" -eq 0 ]; then
    storcli drives.json /call/eall/sall show all
  else
    echo "$SPUN" >"$NEW/drives.skipped"
    storcli drives.json /call/eall/sall show
  fi
fi

lsscsi -g >"$NEW/lsscsi.txt" 2>/dev/null
if command -v sg_ses >/dev/null; then
  sg_ses -V >"$NEW/sg_ses.version" 2>&1
  for sg in $(awk '$2=="enclosu"{print $NF}' "$NEW/lsscsi.txt"); do
    run "ses_${sg##*/}.json" 20 sg_ses --json --join "$sg"
  done
fi

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
for p in /sys/class/sas_phy/phy-*; do
  n=${p##*/}
  [[ $n =~ ^phy-[0-9]+:[0-9]+$ ]] || continue          # host PHYs only, not expander PHYs
  port=$(ls -d /sys/class/sas_port/*/device/"$n" 2>/dev/null | head -1 | awk -F/ '{print $5}')
  att=
  if [ -n "$port" ]; then
    d=$(ls -d /sys/class/sas_port/"$port"/device/{expander,end_device}-* 2>/dev/null | head -1)
    [ -n "$d" ] && att="$(basename "$d")=$(cat /sys/class/sas_device/"$(basename "$d")"/sas_address 2>/dev/null)"
  fi
  echo "$n|$(cat "$p/negotiated_linkrate" 2>/dev/null)|$(cat "$p/maximum_linkrate" 2>/dev/null)|$(cat "$p/sas_address" 2>/dev/null)|$port|$att"
done >"$NEW/sas_phys.txt" 2>/dev/null

for h in /sys/class/sas_host/host*; do
  [ -d "$h" ] || continue
  s=/sys/class/scsi_host/${h##*/}
  echo "${h##*/}|$(cat "$s/proc_name" 2>/dev/null)|$(cat "$s/board_name" 2>/dev/null)|$(cat "$s/version_fw" 2>/dev/null)"
done >"$NEW/sas_hosts.txt" 2>/dev/null

lsblk -dJ -o NAME,SERIAL,WWN,TRAN,MODEL,SIZE >"$NEW/lsblk.json" 2>/dev/null
cp /var/local/emhttp/disks.ini "$NEW/" 2>/dev/null
cp /var/local/emhttp/devs.ini "$NEW/" 2>/dev/null

date +%s >"$NEW/done"
rm -rf "$CACHE/old"
[ -d "$CACHE/current" ] && mv "$CACHE/current" "$CACHE/old"
mv "$NEW" "$CACHE/current"
rm -rf "$CACHE/old"
exit 0

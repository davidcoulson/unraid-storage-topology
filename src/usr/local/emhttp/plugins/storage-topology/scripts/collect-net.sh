#!/bin/bash
# Network Topology collector. Read-only: ethtool queries (settings, -i, -m, -S, -g, --show-fec), lspci,
# `mstflint -d <pci> q` (query only) when installed, `lldpctl` (show neighbours) when installed, and
# /sys and /proc (net devices, bonding, VLANs, bridges, hwmon, PCIe link and AER counters).
# One run at a time (own lock); a finished run replaces the cache in one rename. Each collection keeps an
# older collection's counters in base/ so the page can show what grew since then.
# Usage: collect-net.sh [max_age_seconds]   - does nothing if the cache is younger than that.
CACHE=/var/local/storage-topology/net
LOCK=/var/run/storage-topology-net.lock
MAXAGE=${1:-0}

fresh() { [ -f "$CACHE/current/done" ] && [ $(( $(date +%s) - $(stat -c %Y "$CACHE/current/done") )) -lt "$MAXAGE" ]; }

mkdir -p "$CACHE"
exec 9>"$LOCK"
if ! flock -n 9; then
  flock -w 60 9 || exit 75
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
  timeout -k 3 "$t" nice -n 10 "$@" >"$NEW/$name" 2>"$NEW/$name.err"; rc=$?
  echo "$name|$rc|$(( $(date +%s%3N) - s ))" >>"$NEW/timings"
  [ -s "$NEW/$name.err" ] || rm -f "$NEW/$name.err"
  return $rc
}
rd() { cat "$1" 2>/dev/null; }

# Physical interfaces only: they have a device link. Virtual ones are skipped by name as well.
IFACES=()
for n in /sys/class/net/*; do
  i=${n##*/}
  [ -e "$n/device" ] || continue
  case $i in
    lo|veth*|docker*|br-*|virbr*|vnet*|tap*|wg*|tailscale*|shim*|vhost*) continue ;;
  esac
  IFACES+=("$i")
done

# Driver counters worth keeping from ethtool -S (global ones; per-queue counters are dropped).
KEEP='err|drop|discard|crc|symbol|fec|corrected|link_down|carrier|missed|fifo|out_of_buffer'
QUEUE='^((rx|tx|ch|ptp|xsk|xdp|qos)[0-9]+_|(rx|tx)_queue_[0-9]+_|(rx|tx)-[0-9]+\.|Queue\[|\[[0-9]+\]|.*_q[0-9]+_)'

declare -A PCI
for i in "${IFACES[@]}"; do
  n=/sys/class/net/$i
  bdf=$(basename "$(readlink -f "$n/device")")
  PCI[$bdf]=1
  master=$(basename "$(readlink "$n/master" 2>/dev/null)" 2>/dev/null)
  wl=; [ -d "$n/wireless" ] || [ -e "$n/phy80211" ] && wl=1
  echo "$i|$(rd "$n/address")|$(rd "$n/operstate")|$(rd "$n/carrier")|$(rd "$n/mtu")|$(rd "$n/speed")|$(rd "$n/duplex")|$master|$(rd "$n/carrier_down_count")|$bdf|$(basename "$(readlink "$n/device/driver" 2>/dev/null)" 2>/dev/null)|$wl" >>"$NEW/ifaces.txt"

  run "ethtool_$i" 10 ethtool "$i"
  run "drvinfo_$i" 10 ethtool -i "$i"
  if [ -z "$wl" ]; then
    run "fec_$i" 10 ethtool --show-fec "$i"
    run "ring_$i" 10 ethtool -g "$i"
    run "module_$i" 15 ethtool -m "$i"
  fi
  if run "stats_$i" 15 ethtool -S "$i"; then
    sed -nE 's/^[[:space:]]+(.*[^[:space:]]):[[:space:]]+([0-9]+)$/\1|\2/p' "$NEW/stats_$i" \
      | grep -iE "^[^|]*($KEEP)" | grep -vE "$QUEUE" | sed "s/^/$i|/" >>"$NEW/counters.txt"
  fi
  rm -f "$NEW/stats_$i"
  for s in rx_errors tx_errors rx_dropped tx_dropped rx_crc_errors rx_missed_errors rx_fifo_errors rx_frame_errors \
           rx_length_errors rx_over_errors tx_carrier_errors tx_fifo_errors tx_aborted_errors; do
    [ -f "$n/statistics/$s" ] && echo "$i|sys.$s|$(rd "$n/statistics/$s")"
  done >>"$NEW/counters.txt"
  [ -f "$n/carrier_down_count" ] && echo "$i|sys.carrier_down_count|$(rd "$n/carrier_down_count")" >>"$NEW/counters.txt"
done

# PCI functions behind those interfaces: names, VPD and link (lspci), the upstream port's capability,
# AER totals, and the firmware image on flash for Mellanox/NVIDIA cards (mstflint query, read-only).
declare -A MST
for bdf in $(printf '%s\n' "${!PCI[@]}" | sort); do
  d=/sys/bus/pci/devices/$bdf
  [ -d "$d" ] || continue
  run "lspcim_$bdf" 10 lspci -vmm -nn -s "$bdf"
  run "lspci_$bdf" 10 lspci -vv -s "$bdf"
  up=$(basename "$(dirname "$(readlink -f "$d")")")
  echo "$bdf|$(rd "$d/vendor")|$(rd "$d/device")|$(rd "$d/current_link_speed")|$(rd "$d/current_link_width")|$(rd "$d/max_link_speed")|$(rd "$d/max_link_width")|$up|$(rd "/sys/bus/pci/devices/$up/max_link_speed")|$(rd "/sys/bus/pci/devices/$up/max_link_width")|$(rd "$d/numa_node")" >>"$NEW/pci.txt"
  for a in cor:aer_dev_correctable nonfatal:aer_dev_nonfatal fatal:aer_dev_fatal; do
    [ -f "$d/${a#*:}" ] && echo "pci:$bdf|aer_${a%%:*}|$(awk '$1 ~ /^TOTAL_ERR/ {print $2}' "$d/${a#*:}")"
  done >>"$NEW/counters.txt"
  if [ "$(rd "$d/vendor")" = 0x15b3 ] && command -v mstflint >/dev/null; then
    # One query per card (bus:device), on its lowest network function.
    if [ -z "${MST[${bdf%.*}]}" ]; then
      MST[${bdf%.*}]=1
      run "mstflint_$bdf" 20 mstflint -d "$bdf" q
    fi
  fi
done

# Temperature sensors that belong to those PCI functions.
for h in /sys/class/hwmon/hwmon*; do
  bdf=$(basename "$(readlink -f "$h/device")")
  [ -n "${PCI[$bdf]}" ] || continue
  for t in "$h"/temp*_input; do
    [ -f "$t" ] || continue
    b=${t%_input}
    echo "$bdf|$(rd "$h/name")|$(rd "${b}_label")|$(rd "$t")|$(rd "${b}_crit")|$(rd "${b}_max")|$(rd "${b}_highest")"
  done
done >>"$NEW/hwmon.txt"

# Bonds, VLANs and bridges.
for b in /proc/net/bonding/*; do
  [ -f "$b" ] && cp "$b" "$NEW/bond_${b##*/}.txt"
done
cp /proc/net/vlan/config "$NEW/vlan.txt" 2>/dev/null
for b in /sys/class/net/*/bridge; do
  [ -d "$b" ] || continue
  d=${b%/bridge}
  echo "${d##*/}|$(ls "$d/brif" 2>/dev/null | tr '\n' ' ')|$(rd "$b/stp_state")|$(rd "$b/vlan_filtering")|$(rd "$d/operstate")|$(rd "$d/mtu")"
done >"$NEW/bridges.txt"

# LLDP neighbours from lldpd (e.g. the ulldpd plugin). json0 keeps the same structure for one or many values.
if command -v lldpctl >/dev/null; then
  run lldp.json 10 lldpctl -f json0 || run lldp.json 10 lldpctl -f json
fi

NOW=$(date +%s)
echo "$NOW" >"$NEW/done"

# Baseline for "growth since": the previous collection, unless that one is under 5 minutes old and still
# carries a baseline under an hour old, so frequent refreshes do not shrink the window to a few seconds.
OLD="$CACHE/current"
if [ -f "$OLD/done" ]; then
  oldage=$(( NOW - $(rd "$OLD/done") ))
  if [ -f "$OLD/base/done" ] && [ "$oldage" -lt 300 ] && [ $(( NOW - $(rd "$OLD/base/done") )) -lt 3600 ]; then
    cp -r "$OLD/base" "$NEW/base"
  else
    mkdir -p "$NEW/base"
    cp "$OLD"/counters.txt "$OLD"/bond_*.txt "$OLD/done" "$NEW/base/" 2>/dev/null
  fi
fi

rm -rf "$CACHE/old"
[ -d "$CACHE/current" ] && mv "$CACHE/current" "$CACHE/old"
mv "$NEW" "$CACHE/current"
rm -rf "$CACHE/old"
exit 0

# Storage Topology for Unraid

Two read-only pages in **Tools → System Information**:

- **Storage Topology**: how your SAS storage is wired and how healthy it is.
- **Network Topology**: your physical network cards, ports, transceivers, bonds, bridges, VLANs and the switch
  ports they connect to, with link health and error counters.

## Storage Topology

- **Controllers**: model, firmware, BIOS, driver, mode, chip temperature, and each port's width and per-lane
  link rate, with what is attached to it.
- **Cable map**: which controller port goes to which shelf I/O module port, and shelf-to-shelf cables,
  with cable vendor, part and serial where the enclosure reports them.
- **Disk shelves**: I/O modules (status, firmware, serial), power supplies (status, firmware, rating),
  fans, voltages, currents and temperature sensors, and a bay grid.
- **Bays and drives**: each bay with its Unraid disk name (linked to the disk page), size, model,
  temperature, negotiated vs maximum link rate, both SAS paths, and media/other/predictive-failure counts.
- **Firmware overview**: every controller, I/O module, PSU and drive model with its versions; parts of the
  same kind running different versions are highlighted.
- **Problems list**: failed or degraded elements, firmware mismatches, narrow or slow links, single paths,
  drive errors, and over-temperature enclosures (including NVMe switch cards that expose SES).

Nothing is changed on the system: the plugin only runs `storcli … show … J nolog`, `sg_ses` status pages,
`lsscsi`, `lsblk`, and reads sysfs and emhttp's `disks.ini`.

## Network Topology

- **Cards**: one card per physical NIC (virtual interfaces such as veth, docker, br-*, virbr, vnet, tap, wg,
  tailscale, shim, vhost are skipped): model and part number, PCI address, driver and running firmware, PSID and
  the firmware image on flash (Mellanox/NVIDIA cards, via `mstflint q` when mstflint is installed), PCIe link
  speed and width against what the card supports and what the slot supports, ASIC/PHY temperatures with their
  critical threshold, and PCIe AER error totals.
- **Ports**: MAC, state, speed and lanes, autonegotiation, FEC (active and configured), MTU, ring sizes
  (current/maximum), bond and bridge membership and VLANs.
- **Counters**: link-down events, CRC/symbol errors, FEC-corrected bits, discards and out-of-buffer drops (driver
  names where available, e.g. mlx5 `*_phy` counters, plus the kernel's generic ones), each with its total since boot
  and its growth since an earlier collection, so a counter that is still climbing stands out.
- **Modules and cables** (`ethtool -m`): type, vendor, part, serial, length, and the module's own diagnostics:
  temperature, voltage, and per-lane Rx/Tx power and laser bias, coloured against the module's warning and alarm
  thresholds. Passive copper cables without diagnostics are shown as such.
- **Bonds** (`/proc/net/bonding`): mode, transmit hash policy, LACP rate, the active aggregator and each member's
  aggregator, LACP state, partner, churn state and link failures.
- **Bridges and VLANs**: which bridge each bond or port is in, its VLAN interfaces and their bridges, and the VM and
  container interfaces on the same bridges.
- **LLDP neighbours**: switch name, port, port description, VLAN, model and management address, when `lldpctl`
  (lldpd, for example from the LLDP plugin "ulldpd") is installed and running. Otherwise the section is omitted.
- **Firmware**: NIC model to firmware/PSID, and module part to revision, with differences highlighted. The page does
  not look anything up on the internet, so it does not say whether a newer release exists.
- **Problems list**: PCIe links running narrower or slower than the card supports (a warning when the remaining
  bandwidth is less than the ports need), FEC off on 100G links with AOC/SR4/CR4 modules (RS-FEC is expected and must
  match the switch), module readings outside their thresholds, errors or link drops that grew since the earlier
  collection, bond members that are down, in a different aggregator, not distributing or churned, missing LACP
  partner, temperatures near critical, and ring sizes below the maximum (as a note).

The network page only runs `ethtool` queries (settings, `-i`, `-m`, `-S`, `-g`, `--show-fec`), `lspci`,
`mstflint -d <pci> q` and `lldpctl` (show), and reads `/sys` and `/proc`. It never changes NIC settings.

## Requirements

- Unraid 7.0 or newer.
- `sg_ses` from sg3_utils 1.48 or newer (Unraid 7 ships it) for enclosure health.
- Optional for the network page: `lldpctl` (lldpd, e.g. the LLDP plugin "ulldpd") for switch neighbours, and
  `mstflint` (e.g. the mft-tools plugin) for the flash firmware and PSID of Mellanox/NVIDIA cards. `ethtool` and
  `lspci` ship with Unraid.
- Optional: Broadcom/LSI `storcli` (or `storcli64`) for controller, port and per-drive detail. It is looked
  for in `/sbin`, `/usr/sbin`, `/usr/local/sbin`, `/usr/bin`, `/usr/local/bin` and `/opt/MegaRAID/storcli`.
  Without it the page uses the kernel's view: SAS HBA PHYs and link rates from `/sys/class/sas_phy`, and
  bay-to-disk mapping from `/sys/class/enclosure`. MegaRAID controllers hide their SAS layer from the kernel,
  so they need storcli.

## How it collects

Data is collected when the page opens and cached for 60 seconds (**Refresh** forces a new collection, at most
every 5 seconds). One collection runs at a time; each command has a timeout, and after a storcli timeout
no further storcli commands run in that collection, so a stalled controller does not get a queue of
commands. A full collection normally takes well under a second.

Per-drive storcli detail is skipped while any Unraid disk is spun down, so opening the page never wakes a
drive. storcli serialises its own calls, so if another tool (for example a monitoring agent) is running
storcli at the same moment, a collection can take longer.

Cache: `/var/local/storage-topology/current` (RAM).

The network page works the same way with its own lock and cache, `/var/local/storage-topology/net/current`. Its
"growth" figures compare against an earlier collection kept in `net/current/base`: the previous one, or, while the
page is refreshed often, one between 5 minutes and an hour old, so the window does not shrink to a few seconds.
A full network collection takes about a second (the `mstflint` query is the slowest part).

## What has been tested

- Broadcom MegaRAID 9580-8i8e in JBOD mode with two NetApp DS424 (IOM12) shelves, multipathed.
- HighPoint Rocket 1528D NVMe switch card (SES temperature, fan and slot status).
- Network: NVIDIA/Mellanox ConnectX-6 Dx dual-port 100G (mlx5) in an 802.3ad bond with 100G AOC cables and LLDP
  from the ulldpd plugin, Aquantia AQC113 10GBASE-T (atlantic), Intel AX210 Wi-Fi. SFP/SFP+ modules, other drivers'
  counter names and other bond modes are handled from the documented `ethtool`/bonding formats but have had less
  real-hardware testing.

The kernel-view path for LSI HBAs in IT mode (mpt3sas) is implemented but has not been tested on real
IT-mode hardware yet. Reports and fixtures from other controllers and enclosures are very welcome: please
open an issue with the output of the commands above (remove serial numbers if you prefer).

Labels such as "IOM A/B", port numbers and cable details come from the enclosure's SES element order and
NetApp's descriptor format. Other enclosures still get status and sensors, with fewer labels.

## Support

Unraid forum thread: https://forums.unraid.net/topic/200846-plugin-storage-topology-sas-controllers-cables-shelves-and-bays-at-a-glance/
Bugs and hardware reports can also go to GitHub issues.

## Install

Plugins → Install Plugin, and paste:

```
https://raw.githubusercontent.com/davidcoulson/unraid-storage-topology/main/storage-topology.plg
```

## Build a release

```bash
./build.sh 2026.10.10
```

This builds `archive/storage-topology-<version>-x86_64-1.txz` and stamps the version and MD5 into
`storage-topology.plg`. Add a `<CHANGES>` entry, commit, push, and attach the `.txz` to a GitHub release
tagged with the version.

## License

MIT, see [LICENSE](LICENSE).

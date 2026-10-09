# Storage Topology for Unraid

Two read-only pages in **Tools → System Information**:

- **Storage Topology**: how your SAS storage is wired and how healthy it is.
- **Network Topology**: your physical network cards, ports, transceivers, bonds, bridges, VLANs and the switch
  ports they connect to, with link health and error counters.

## Storage Topology

- **Controllers**: model, firmware, BIOS, driver, mode, chip temperature, and each port's width (wide ports
  called out), per-lane link rate and what is attached to it: a shelf I/O module or expander, or the disk itself
  (Unraid name, /dev name and model) for drives straight on the HBA.
- **Connectors**: per SFF-8643/8644 connector (4 PHYs each, derived from PHY numbers, so C0, C1, ... may not match
  the labels on the card), how many lanes are linked, at what rate, to which port and device. Unused PHYs are listed
  per connector.
- **Directly attached drives**: drives that are in no enclosure bay (on an HBA PHY, on an expander PHY that no SES
  bay points to, or on a MegaRAID controller without an enclosure or in a virtual one such as EID 252), with port,
  disk, model, size, temperature and link rate.
- **Cable map**: which controller port goes to which shelf I/O module port, and shelf-to-shelf cables,
  with cable vendor, part and serial where the enclosure reports them.
- **Disk shelves**: I/O modules (status, firmware, serial), power supplies (status, firmware, rating),
  fans, voltages, currents and temperature sensors, and a bay grid.
- **Bays and drives**: each bay with its Unraid disk name (linked to the disk page), size, model,
  temperature, negotiated vs maximum link rate, both SAS paths, and media/other/predictive-failure counts.
- **Firmware overview**: every controller, I/O module, PSU and drive model with its versions; parts of the
  same kind running different versions are highlighted.
- **Simple enclosures**: USB drive boxes and enclosures that only support the SES short status page (one status
  byte: overall status only, no per-bay, fan or PSU detail), shown with their disks.
- **Problems list**: failed or degraded elements (with plain-language PSU messages such as "no AC input"), firmware
  mismatches, partially linked connectors, slow links, single paths, drive errors, and over-temperature enclosures
  (including NVMe switch cards that expose SES). Link rates are judged against what both ends support: an expander's
  PHY maximum (e.g. 6 Gb/s for SAS2 expanders), 6 Gb/s for SATA drives; SAS drives that do not report their maximum
  are not judged.
- **Acknowledge**: each problem can be acknowledged. It then moves to a collapsed "Acknowledged" list and no
  longer counts toward the page status, until what it reports changes (a status, a flag, a counter), when it shows
  again. Acknowledgements are kept in `/boot/config/plugins/storage-topology/acks.json`. Same on the network page.
- **Download diagnostics**: a `.tar.gz` of everything both pages collected, with plugin, Unraid, kernel, sg_ses and
  storcli versions, for bug reports. "Anonymise" (on by default) replaces serial numbers, WWNs, SAS addresses, MAC
  addresses, the hostname and LLDP switch names consistently, so the topology stays readable, and reduces
  `disks.ini`/`devs.ini` to name, device, type and status.

Nothing is changed on the system: the plugin only runs `storcli … show … J nolog`, `sg_ses` status and
configuration pages, `lsscsi`, `lsblk`, and reads sysfs and emhttp's `disks.ini`. The only file it writes outside
its RAM cache is `acks.json`, and only when you press Acknowledge.

Enclosure data comes from `sg_ses --json --join`. Where that fails (sg_ses 2.86, from sg3_utils 1.48, crashes there
on enclosures without an element descriptor page, such as the EMC KTN-STL3), the collector reads the pages it joins
one at a time (configuration, enclosure status, element descriptors and additional element status; the last two are
optional) and the page joins them the same way sg_ses does. Which way was used is noted in the diagnostics README.

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
  Without it the page uses the kernel's view: SAS HBA and expander PHYs and link rates from `/sys/class/sas_phy`,
  expanders and end devices from `/sys/class/sas_expander` and `/sys/class/sas_device`, and bay-to-disk mapping
  from `/sys/class/enclosure`. MegaRAID controllers hide their SAS layer from the kernel, so they need storcli.
  storcli also works with SAS3/SAS3.5 HBAs in IT mode (SAS3008, SAS3216/3224, SAS3408/3416 and later, e.g. 9300, 9305,
  9400, 9500 series); the page then shows the HBA's model, firmware and, on chips with a sensor (e.g. 9400/9500
  series), its temperature. Some OEM IT-mode firmware (e.g. Inspur's SAS3008) gives storcli no controller status and
  no port data: the status then shows as "not reported" (a note, not a fault) and the ports come from the kernel's
  view. SAS2 HBAs (SAS2008/SAS2308, e.g. 9211-8i, 9207-8i, and their OEM versions) are not
  supported by storcli (it reports 0 controllers): the page shows them from the kernel view, and their chip
  temperature needs other tools; the plugin does not read it. lsiutil is not used.

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
- From user reports and synthetic test fixtures (`tests/`): LSI SAS9305-24i in IT mode with SATA SSDs on the HBA
  and an LSI SAS2X28 expander backplane, 9400-16i-like wide ports and dual-expander backplanes, EMC KTN-STL3 (VNX
  DAE, named from the SES configuration page; also with sg_ses 2.86, whose `--json --join` crashes on it), WD My Book USB
  enclosures (short status page only).
- Network: NVIDIA/Mellanox ConnectX-6 Dx dual-port 100G (mlx5) in an 802.3ad bond with 100G AOC cables and LLDP
  from the ulldpd plugin, Aquantia AQC113 10GBASE-T (atlantic), Intel AX210 Wi-Fi. SFP/SFP+ modules, other drivers'
  counter names and other bond modes are handled from the documented `ethtool`/bonding formats but have had less
  real-hardware testing.

Reports from other controllers and enclosures are very welcome: please open an issue (or post in the forum
thread) and attach the file from **Download diagnostics**, anonymised if you prefer.

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
./build.sh 2026.10.12
```

This runs the tests (`php tests/run.php`, when PHP is installed), builds `archive/storage-topology-<version>-x86_64-1.txz` and stamps the version and MD5 into
`storage-topology.plg`. Add a `<CHANGES>` entry, commit, push, and attach the `.txz` to a GitHub release
tagged with the version.

## Tests

`tests/fixtures/` holds synthetic collector output (made by `tests/make_fixtures.py`, no real serials or addresses)
for layouts the author's server does not have, plus real data anonymised with the plugin's own anonymiser: a NetApp
DS424's `--join` output with the pages it joins (`netapp-ds424-pages`, to check the page's own join against
sg_ses's), and an EMC KTN-STL3 configuration page (`tests/data/`). `php tests/run.php` loads each through the page's
model, checks the problems list, acknowledgements (against a temporary store), anonymisation, and renders the page
with all PHP notices enabled.

## License

MIT, see [LICENSE](LICENSE).

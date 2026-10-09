# Storage Topology for Unraid

A read-only page in **Tools → System Information → Storage Topology** that shows how your SAS storage is
wired and how healthy it is:

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

## Requirements

- Unraid 7.0 or newer.
- `sg_ses` from sg3_utils 1.48 or newer (Unraid 7 ships it) for enclosure health.
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

## What has been tested

- Broadcom MegaRAID 9580-8i8e in JBOD mode with two NetApp DS424 (IOM12) shelves, multipathed.
- HighPoint Rocket 1528D NVMe switch card (SES temperature, fan and slot status).

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
./build.sh 2026.10.09
```

This builds `archive/storage-topology-<version>-x86_64-1.txz` and stamps the version and MD5 into
`storage-topology.plg`. Add a `<CHANGES>` entry, commit, push, and attach the `.txz` to a GitHub release
tagged with the version.

## License

MIT, see [LICENSE](LICENSE).

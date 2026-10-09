#!/bin/bash
# Build the Slackware package for a release and stamp its version and MD5 into storage-topology.plg.
# Usage: ./build.sh [version]    (default: today's date, YYYY.MM.DD)
# Then: commit, push, and attach archive/storage-topology-<version>-x86_64-1.txz to a GitHub release tagged <version>.
set -euo pipefail
cd "$(dirname "$0")"
VERSION=${1:-$(date +%Y.%m.%d)}
PKG=storage-topology-$VERSION-x86_64-1.txz
SRC=src
PLUG=$SRC/usr/local/emhttp/plugins/storage-topology

for f in "$PLUG"/include/*.php; do
  if command -v php >/dev/null; then php -l "$f" >/dev/null; fi
done
for f in "$PLUG"/scripts/*.sh; do bash -n "$f"; done
# Model tests on the synthetic fixtures in tests/ (skipped when PHP is not installed, e.g. on a Mac without it).
if command -v php >/dev/null; then php tests/run.php; else echo "php not found: skipping tests/run.php"; fi

chmod 755 "$PLUG/scripts/"*.sh
find "$SRC" -type d -exec chmod 755 {} +
find "$SRC" -type f ! -path '*/scripts/*' -exec chmod 644 {} +

mkdir -p archive
rm -f "archive/$PKG"
# Root-owned, no macOS metadata, paths relative to / as installpkg expects.
COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata --uid 0 --gid 0 --uname root --gname root \
  -C "$SRC" -cJf "archive/$PKG" usr

MD5=$(md5 -q "archive/$PKG" 2>/dev/null || md5sum "archive/$PKG" | cut -d' ' -f1)
sed -i.bak -E \
  -e "s|(<!ENTITY version +\")[^\"]*\"|\1$VERSION\"|" \
  -e "s|(<!ENTITY md5 +\")[^\"]*\"|\1$MD5\"|" storage-topology.plg
rm -f storage-topology.plg.bak
grep -q "^###$VERSION" storage-topology.plg || echo "Remember to add a ###$VERSION entry to <CHANGES> in storage-topology.plg"
echo "Built archive/$PKG (md5 $MD5)"
tar -tJf "archive/$PKG"

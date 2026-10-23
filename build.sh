#!/usr/bin/env bash
# Builds dist/snapexpay-blesta-<version>.zip: Blesta-root layout plus README.
set -euo pipefail
cd "$(dirname "$0")"
gw=src/components/gateways/nonmerchant/snapex_pay
version=$(grep -oP "const VERSION = '\K[^']+" "$gw/snapex_pay.php")
config_version=$(grep -oP '"version": "\K[^"]+' "$gw/config.json")
[ "$version" = "$config_version" ] || { echo "VERSION $version != config.json $config_version" >&2; exit 1; }
php tests/run.php >/dev/null
rm -rf dist && mkdir -p dist/tmp
cp -r src/. dist/tmp/
cp README.md dist/tmp/README.md
# Files must be readable by the web server whatever umask built them.
find dist/tmp -type d -exec chmod 755 {} + && find dist/tmp -type f -exec chmod 644 {} +
(cd dist/tmp && zip -qrX "../snapexpay-blesta-${version}.zip" .)
rm -rf dist/tmp
echo "dist/snapexpay-blesta-${version}.zip"

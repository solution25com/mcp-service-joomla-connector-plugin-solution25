#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
dist="$root/dist"
work="$dist/work"
version="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$root/src/pkg_aiconnector.xml")"
package="$dist/pkg_aiconnector-$version.zip"

rm -rf "$dist"
mkdir -p "$work/packages"

(cd "$root/src/com_aiconnector" && zip -qr "$work/packages/com_aiconnector.zip" . -x '.*')
(cd "$root/src/plg_system_aiconnector" && zip -qr "$work/packages/plg_system_aiconnector.zip" . -x '.*')
(cd "$root/src/plg_webservices_aiconnector" && zip -qr "$work/packages/plg_webservices_aiconnector.zip" . -x '.*')
(cd "$root/src/plg_api-authentication_aiconnector" && zip -qr "$work/packages/plg_api-authentication_aiconnector.zip" . -x '.*')
cp "$root/src/pkg_aiconnector.xml" "$root/src/script.php" "$work/"
cp -R "$root/src/language" "$work/language"

(cd "$work" && zip -qr "$package" . -x '.*')
rm -rf "$work"
echo "$package"

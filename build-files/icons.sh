#!/bin/sh
# Renders public/assets/favicon-32.png and public/assets/apple-touch-icon.png from their SVGs. Run it after changing
# public/assets/favicon.svg or build-files/apple-touch-icon.svg, and commit the PNGs: the app and the zip need nothing
# of this. Needs Node and npm; @resvg/resvg-js is fetched into a temporary directory, at a fixed version so that the
# same SVG always gives the same PNG.
#   sh build-files/icons.sh
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
# dash runs the EXIT trap on exit only: a signal exits first, so the temporary directory still goes.
trap 'exit 1' HUP INT TERM

npm install --prefix "$tmp" --no-save --no-audit --no-fund --silent @resvg/resvg-js@2.6.2
# Node looks for a module from the script's own folder upwards before NODE_PATH, so a node_modules above this
# repository would win over the pinned version. Run from the temporary directory, where only the pinned one is.
cp "$root/build-files/icons.cjs" "$tmp/icons.cjs"
node "$tmp/icons.cjs" "$root"

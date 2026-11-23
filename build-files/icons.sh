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

npm install --prefix "$tmp" --no-save --no-audit --no-fund --silent @resvg/resvg-js@2.6.2
NODE_PATH="$tmp/node_modules" node "$root/build-files/icons.cjs" "$root"

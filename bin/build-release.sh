#!/bin/sh
# bin/build-release.sh VERSION
#
# Builds build/re-buji-VERSION.zip: the committed tree with vendor/ installed for production, without tests or
# developer files. The zip is what owners upload over FTP (rental servers without SSH cannot run composer).

set -eu

version="${1:?Usage: bin/build-release.sh <version>}"
root=$(cd "$(dirname "$0")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
app="$work/re-buji"

mkdir -p "$app"
git -C "$root" archive HEAD | tar -x -C "$app"

# The zip carries the version its code says it is: it shows on the database update screen and goes to JMA.
code=$(php -r 'require $argv[1]; echo ReBuji\Version::CURRENT;' "$app/src/Version.php")

if [ "$code" != "$version" ]; then
    echo "src/Version.php says $code, not $version; change it and commit first" >&2
    exit 1
fi

# A zip built after its tag would not be what the tag says it is. No tag yet is fine: the tag comes after the zip.
tagged=$(git -C "$root" rev-parse -q --verify "refs/tags/v$version^{commit}" || true)

if [ -n "$tagged" ] && [ "$tagged" != "$(git -C "$root" rev-parse HEAD)" ]; then
    echo "tag v$version is at $(git -C "$root" rev-parse --short "$tagged"), not HEAD; check out the tag or bump the version" >&2
    exit 1
fi

if [ -n "$(git -C "$root" status --porcelain --untracked-files=normal)" ]; then
    echo "warning: uncommitted or untracked files are not in the zip" >&2
fi

(cd "$app" && composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet)

rm -rf "$app/tests" "$app/docs" "$app/.github" "$app/build-files" \
    "$app/phpunit.xml.dist" "$app/psalm.xml.dist" "$app/pint.json" "$app/bin/build-release.sh"
cp "$root/build-files/vendor.htaccess" "$app/vendor/.htaccess"

files=$(find "$app/vendor" -type f | wc -l | tr -d ' ')
echo "vendor/ files: $files"

if [ "$files" -gt 1800 ]; then
    echo "vendor/ has more than 1,800 files; uploading over FTP would be too slow" >&2
    exit 1
fi

mkdir -p "$root/build"
zip="$root/build/re-buji-$version.zip"
rm -f "$zip"

# Folders 0755 and files 0644 (0755 if executable), since scp and rsync carry the modes in the zip to the server.
php "$root/build-files/zip.php" "$app" "$zip"

echo "built $zip"

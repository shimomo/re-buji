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

# PHP's zip extension, since rental-server-minded machines may lack the zip command.
php -r '
    [$source, $target] = array_slice($argv, 1);
    $zip = new ZipArchive();
    $zip->open($target, ZipArchive::CREATE) === true or exit(1);
    $base = dirname($source);
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($entries as $entry) {
        $name = substr($entry->getPathname(), strlen($base) + 1);
        $entry->isDir() ? $zip->addEmptyDir($name) : $zip->addFile($entry->getPathname(), $name);
    }
    $zip->close() or exit(1);
' "$app" "$zip"

echo "built $zip"

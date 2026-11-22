#!/bin/sh
# bin/release.sh check VERSION
# bin/release.sh publish VERSION NOTES_FILE
#
# Releases Re:無事 from main, the same way every time.
#   check    makes sure main is clean, pushed and carries VERSION, and that tag vVERSION is new; then builds
#            build/re-buji-VERSION.zip. Nothing leaves this machine.
#   publish  does the same checks and build, then makes the signed tag vVERSION, pushes it, and creates the GitHub
#            release with the zip and NOTES_FILE. `gh release create --verify-tag` refuses to make a tag of its own,
#            so every release tag is a signed, annotated one.

set -eu

usage() {
    echo "usage: bin/release.sh check VERSION | bin/release.sh publish VERSION NOTES_FILE" >&2
    exit 1
}

fail() {
    echo "$1" >&2
    exit 1
}

[ $# -ge 2 ] || usage
command="$1"
version="$2"
notes=""

case "$command" in
    check) [ $# -eq 2 ] || usage ;;
    publish) [ $# -eq 3 ] || usage; notes="$3" ;;
    *) usage ;;
esac

root=$(cd "$(dirname "$0")/.." && pwd)
tag="v$version"

if [ "$command" = publish ] && [ ! -s "$notes" ]; then
    fail "release notes not found or empty: $notes"
fi

branch=$(git -C "$root" rev-parse --abbrev-ref HEAD)
[ "$branch" = main ] || fail "release from main, not $branch"

if [ -n "$(git -C "$root" status --porcelain --untracked-files=normal)" ]; then
    echo "the working tree is not clean; commit or remove these first:" >&2
    git -C "$root" status --porcelain --untracked-files=normal >&2
    exit 1
fi

git -C "$root" fetch -q origin main
[ "$(git -C "$root" rev-parse HEAD)" = "$(git -C "$root" rev-parse origin/main)" ] \
    || fail "main is not the same as origin/main; pull or push first"

code=$(php -r 'require $argv[1]; echo ReBuji\Version::CURRENT;' "$root/src/Version.php")
[ "$code" = "$version" ] || fail "src/Version.php says $code, not $version; change it in a pull request first"

if git -C "$root" rev-parse -q --verify "refs/tags/$tag" > /dev/null \
    || git -C "$root" ls-remote --exit-code --tags origin "refs/tags/$tag" > /dev/null 2>&1; then
    fail "tag $tag already exists; bump the version"
fi

"$root/bin/build-release.sh" "$version"
zip="$root/build/re-buji-$version.zip"

if command -v sha256sum > /dev/null; then
    sum=$(sha256sum "$zip" | cut -d ' ' -f 1)
else
    sum=$(shasum -a 256 "$zip" | cut -d ' ' -f 1)
fi

echo "sha256 $sum"

if [ "$command" = check ]; then
    echo "ready: run bin/release.sh publish $version NOTES_FILE once the notes are written and the author agrees"
    exit 0
fi

git -C "$root" tag -s "$tag" -m "Re:無事 $version"
git -C "$root" tag -v "$tag" > /dev/null 2>&1 || fail "tag $tag is not signed; fix gpg and delete the tag before trying again"
git -C "$root" push -q origin "$tag"

(cd "$root" && gh release create "$tag" "$zip" --verify-tag --title "$tag" --notes-file "$notes")

digest=$(cd "$root" && gh release view "$tag" --json assets -q '.assets[] | select(.name == "re-buji-'"$version"'.zip") | .digest')
[ "$digest" = "sha256:$sum" ] || fail "the uploaded zip is $digest, not sha256:$sum; check the release by hand"

echo "released $tag (sha256 $sum): $(cd "$root" && gh release view "$tag" --json url -q .url)"

#!/bin/sh
# sh bin/upgrade.sh [--public DIR] [--yes] [--version X.Y.Z] [--zip FILE] [--root DIR]
#
# Upgrades this copy of Re:無事 to the latest release, over SSH on the server itself:
#   1. asks GitHub for the latest release (or --version) and stops if it is not newer than this copy
#   2. downloads re-buji-<version>.zip and checks its sha256 against the one GitHub publishes
#   3. unpacks it inside storage/ and hands over to the new copy's `bin/console upgrade:install`, which asks before it
#      changes anything, keeps a copy of the old files and the database, replaces everything but storage/, applies the
#      database changes and, with --public, refreshes index.php and assets/ in the public directory
#
# --public DIR  the public directory, when only public/ is in the document root (remembered after the first time)
# --yes         do not ask before upgrading
# --version     install that version instead of the latest
# --zip FILE    install from a zip already on the server instead of downloading (its sha256 is shown, not checked)
# --root DIR    the copy to upgrade, when this script is not inside it (the first upgrade from 0.3.0 or earlier)
#
# PHP=/path/to/php picks the php to run, like the one cron uses. Plain sh: works with FreeBSD's sh and Linux's dash.

set -eu

# The copied files keep the zip's modes (755/644) whatever the SSH session's umask is.
umask 022

repo=shimomo/re-buji
php=${PHP:-php}
root=""
public=""
yes=""
version=""
zip=""

fail() {
    echo "$1" >&2
    exit 1
}

usage() {
    echo "使い方：sh bin/upgrade.sh [--public DIR] [--yes] [--version X.Y.Z] [--zip FILE] [--root DIR]" >&2
    exit 2
}

while [ $# -gt 0 ]; do
    case "$1" in
        --public) [ $# -ge 2 ] || usage; public="$2"; shift 2 ;;
        --public=*) public="${1#--public=}"; shift ;;
        --root) [ $# -ge 2 ] || usage; root="$2"; shift 2 ;;
        --root=*) root="${1#--root=}"; shift ;;
        --version) [ $# -ge 2 ] || usage; version="${2#v}"; shift 2 ;;
        --version=*) version="${1#--version=}"; version="${version#v}"; shift ;;
        --zip) [ $# -ge 2 ] || usage; zip="$2"; shift 2 ;;
        --zip=*) zip="${1#--zip=}"; shift ;;
        --yes) yes=1; shift ;;
        -h|--help) usage ;;
        *) usage ;;
    esac
done

if [ "${REBUJI_ENV_CONFIG:-}" = 1 ]; then
    fail "Docker では使いません。docker compose pull で新しいイメージを取ってください。"
fi

[ -n "$root" ] || root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
root=$(CDPATH= cd -- "$root" 2>/dev/null && pwd) || fail "設置した場所がありません：$root"
[ -f "$root/src/Version.php" ] && [ -d "$root/storage" ] || fail "$root に設置した Re:無事 が見つかりません。"

command -v "$php" > /dev/null 2>&1 || fail "php が見つかりません。env PHP=/path/to/php を付けて動かしてください。"
"$php" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' > /dev/null 2>&1 \
    || fail "$php はコマンドラインの php ではありません。env PHP=/path/to/php を付けて動かしてください。"
command -v unzip > /dev/null 2>&1 || fail "unzip が見つかりません。"

current=$(sed -n "s/.*CURRENT = '\([^']*\)'.*/\1/p" "$root/src/Version.php")
[ -n "$current" ] || fail "$root/src/Version.php から版を読めません。"

# Inside storage/: the web never serves it, and it is on the same disk as the copy, so the new files can be moved into
# place rather than copied.
work="$root/storage/upgrade-work-$$"
mkdir -m 700 "$work" || fail "作業用のディレクトリを作れません：$work"
trap 'rm -rf "$work"' EXIT
trap 'exit 1' HUP INT TERM

sha256() {
    if command -v sha256sum > /dev/null 2>&1; then
        sha256sum "$1" | cut -d ' ' -f 1
    elif command -v shasum > /dev/null 2>&1; then
        shasum -a 256 "$1" | cut -d ' ' -f 1
    else
        openssl dgst -sha256 "$1" | sed 's/.*= *//'
    fi
}

if [ -n "$zip" ]; then
    [ -f "$zip" ] || fail "zip がありません：$zip"
    echo "zip を使います：$zip（sha256 $(sha256 "$zip")）"
    cp "$zip" "$work/release.zip"
else
    command -v curl > /dev/null 2>&1 || fail "curl が見つかりません。"

    if [ -n "$version" ]; then
        api="https://api.github.com/repos/$repo/releases/tags/v$version"
    else
        api="https://api.github.com/repos/$repo/releases/latest"
    fi

    # bin/install.sh carries a copy of this download and sha256 check (it is downloaded alone): change both together.
    curl -fsSL -H 'Accept: application/vnd.github+json' -o "$work/release.json" "$api" \
        || fail "GitHub からリリースの情報を取れませんでした：$api"

    # The tag, the zip's URL and its sha256, one per line. php is already needed here, so no jq.
    "$php" -d display_errors=stderr -r '
        $release = json_decode((string) file_get_contents($argv[1]), true);
        $tag = is_array($release) && is_string($release["tag_name"] ?? null) ? $release["tag_name"] : "";
        $version = ltrim($tag, "v");
        foreach (is_array($release["assets"] ?? null) ? $release["assets"] : [] as $asset) {
            if (is_array($asset) && ($asset["name"] ?? "") === "re-buji-" . $version . ".zip") {
                echo $version, "\n", $asset["browser_download_url"] ?? "", "\n", preg_replace("/^sha256:/", "", (string) ($asset["digest"] ?? "")), "\n";
                exit(0);
            }
        }
        exit(1);
    ' "$work/release.json" > "$work/release.txt" || fail "リリースに re-buji-<版>.zip が見つかりません。"

    latest=$(sed -n 1p "$work/release.txt")
    url=$(sed -n 2p "$work/release.txt")
    expected=$(sed -n 3p "$work/release.txt")

    # Only a version number, this repository's download URL for it and a 64-digit hex hash are taken from the answer.
    case "$latest" in
        ''|*[!0-9.]*) fail "リリースの版を読めません：$latest" ;;
    esac

    case "$url" in
        "https://github.com/$repo/releases/download/v$latest/re-buji-$latest.zip") ;;
        *) fail "zip の URL が思っていたものと違うので、止めます：$url" ;;
    esac

    if ! "$php" -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$latest" "$current"; then
        echo "最新です（$current）。"
        exit 0
    fi

    case "$expected" in
        *[!0-9a-f]*|'') fail "GitHub が zip の sha256 を示していないので、止めます。" ;;
    esac

    [ ${#expected} -eq 64 ] || fail "GitHub が zip の sha256 を示していないので、止めます。"
    echo "$current から $latest に上げます。zip を取っています…"
    curl -fsSL --proto '=https' --proto-redir '=https' -o "$work/release.zip" "$url" || fail "zip を取れませんでした：$url"

    actual=$(sha256 "$work/release.zip")

    if [ "$actual" != "$expected" ]; then
        fail "zip の sha256 が GitHub の示すものと違うので、止めます（GitHub：$expected、取った zip：$actual）。"
    fi

    echo "zip の sha256 を確かめました：$actual"
fi

unzip -q "$work/release.zip" -d "$work/unpacked" || fail "zip を展開できませんでした。"
[ -f "$work/unpacked/re-buji/src/Console/UpgradeInstallCommand.php" ] \
    || fail "この版は bin/upgrade.sh で入れられません。zip を展開して上書きしてください。"

set -- --target="$root"
[ -z "$public" ] || set -- "$@" --public="$public"
[ -z "$yes" ] || set -- "$@" --yes

# The new copy knows how it is installed. From here a dropped SSH connection or Ctrl-C must not stop it halfway through
# replacing the files: hang-ups and interrupts are ignored, and php inherits that. It still asks before it starts.
trap '' HUP INT
status=0
"$php" "$work/unpacked/re-buji/bin/console" upgrade:install "$@" || status=$?
exit "$status"

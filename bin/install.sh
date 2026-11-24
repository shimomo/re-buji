#!/bin/sh
# sh install.sh [--public DIR] [--root DIR] [--url URL] [--config FILE] [--version X.Y.Z] [--zip FILE]
#
# Installs Re:無事 over SSH on the server itself. Download this one file and run it:
#   curl -fsSLO https://github.com/shimomo/re-buji/releases/latest/download/install.sh
#   sh install.sh --public ~/www/anpi
# It finds a command-line php 8.3 or newer, checks where the files would go (all before downloading anything), downloads
# re-buji-<version>.zip and checks its sha256 against the one GitHub publishes, unpacks it next to --root and moves it into
# place in one step, then hands over to the new copy's `bin/console install`, which asks for the rest (database, administrator,
# mail, the check mail's reply, cron). Run it again with the same arguments to continue from where it stopped.
#
# --public DIR   the public directory, when only public/ is in the document root: the copy goes to ~/re-buji and only the
#                contents of public/ go here (recommended)
# --root DIR     where the whole copy goes, when it must sit inside the public directory (default: ~/re-buji)
# --url URL      the URL the public directory is served at (asked for when not given)
# --config FILE  an env-style file of answers (REBUJI_DB_*, REBUJI_MAIL_*, ...) so that fewer questions are asked
# --version      install that version instead of the latest
# --zip FILE     install from a zip already on the server instead of downloading (its sha256 is shown, not checked)
#
# PHP=/path/to/php picks the php to run, like the one cron uses. Plain sh: works with FreeBSD's sh and Linux's dash.
# The download and sha256 check below is a copy of the one in bin/upgrade.sh (this file is downloaded alone, and sh has
# no way to load another file): change both together.

set -eu

# The copied files keep the zip's modes (755/644) whatever the SSH session's umask is.
umask 022

repo=shimomo/re-buji
home=${HOME:-}
root=""
public=""
url=""
config=""
version=""
zip=""
work=""

fail() {
    echo "$1" >&2
    exit 1
}

usage() {
    echo "使い方：sh install.sh [--public DIR] [--root DIR] [--url URL] [--config FILE] [--version X.Y.Z] [--zip FILE]" >&2
    exit 2
}

while [ $# -gt 0 ]; do
    case "$1" in
        --public) [ $# -ge 2 ] || usage; public="$2"; shift 2 ;;
        --public=*) public="${1#--public=}"; shift ;;
        --root) [ $# -ge 2 ] || usage; root="$2"; shift 2 ;;
        --root=*) root="${1#--root=}"; shift ;;
        --url) [ $# -ge 2 ] || usage; url="$2"; shift 2 ;;
        --url=*) url="${1#--url=}"; shift ;;
        --config) [ $# -ge 2 ] || usage; config="$2"; shift 2 ;;
        --config=*) config="${1#--config=}"; shift ;;
        --version) [ $# -ge 2 ] || usage; version="${2#v}"; shift 2 ;;
        --version=*) version="${1#--version=}"; version="${version#v}"; shift ;;
        --zip) [ $# -ge 2 ] || usage; zip="$2"; shift 2 ;;
        --zip=*) zip="${1#--zip=}"; shift ;;
        -h|--help) usage ;;
        *) usage ;;
    esac
done

if [ "${REBUJI_ENV_CONFIG:-}" = 1 ]; then
    fail "Docker では使いません。docs/docker.md の手順で動かしてください。"
fi

# --- php ---------------------------------------------------------------------------------------------------------------

# Prints PHP_VERSION_ID and succeeds when $1 is a command-line php 8.3 or newer.
php_id() {
    "$1" -r 'if (PHP_SAPI === "cli" && PHP_VERSION_ID >= 80300) { echo PHP_VERSION_ID; exit(0); } exit(1);' 2> /dev/null
}

php=""

if [ -n "${PHP:-}" ]; then
    php=$(command -v -- "$PHP" 2> /dev/null) || php=""

    if [ -z "$php" ] || ! php_id "$php" > /dev/null; then
        fail "$PHP は 8.3 以上のコマンドラインの php ではありません。"
    fi
else
    best=0
    # The php on PATH first, then the versioned ones some hosts keep out of PATH; the newest that qualifies wins.
    # Where each host keeps its php, only as far as the host's own manual states it:
    #   ロリポップ！: /usr/local/php/<x.x>/bin/php, "SSHでPHPを利用する場合" (https://lolipop.jp/manual/user/php-setting/)
    #   エックスサーバー: /usr/bin/php8.3, php8.4, php8.5, "コマンドパス一覧" (https://www.xserver.ne.jp/manual/man_program_soft.php)
    #   ConoHa WING: /opt/alt/php83/usr/bin/php (also php84, php85), "プログラム言語のパスを教えてください。"
    #     (https://support.conoha.jp/wing/faq/website-q/website-setting-q/)
    # Not found in the official manual, so nothing is added for them: さくらのレンタルサーバ (PATH's php or env PHP=).
    for candidate in "$(command -v php 2> /dev/null || true)" /usr/local/php/*/bin/php /usr/bin/php8.3 /usr/bin/php8.4 /usr/bin/php8.5 \
        /opt/alt/php83/usr/bin/php /opt/alt/php84/usr/bin/php /opt/alt/php85/usr/bin/php; do
        [ -n "$candidate" ] && [ -x "$candidate" ] || continue
        id=$(php_id "$candidate") || continue

        if [ "$id" -gt "$best" ]; then
            best=$id
            php=$candidate
        fi
    done

    [ -n "$php" ] || fail "8.3 以上のコマンドラインの php が見つかりません。env PHP=/path/to/php sh install.sh … のように、php を渡してください。"
fi

# cron runs it from another directory, so it must be absolute.
case "$php" in
    /*) ;;
    *) php="$(pwd)/$php" ;;
esac

echo "php：$php（$("$php" -r 'echo PHP_VERSION;')）を使います"

# --- where the files go --------------------------------------------------------------------------------------------------

# Prints $1 as an absolute path: the parent resolved with cd -P, the last name as written (it need not exist yet).
absolute() {
    p=$1

    while :; do
        case "$p" in
            ?*/) p=${p%/} ;;
            *) break ;;
        esac
    done

    if [ -d "$p" ]; then
        (CDPATH= cd -P -- "$p" 2> /dev/null && pwd -P)
        return
    fi

    case "$p" in
        */*) parent=${p%/*}; base=${p##*/} ;;
        *) parent=.; base=$p ;;
    esac

    [ -n "$parent" ] || parent=/
    parent=$(CDPATH= cd -P -- "$parent" 2> /dev/null && pwd -P) || return 1

    if [ "$parent" = / ]; then
        echo "/$base"
    else
        echo "$parent/$base"
    fi
}

# The first ten names in $1, joined with 、.
names() {
    ls -A "$1" | head -n 10 | sed '2,$s/^/、/' | tr -d '\n'
}

# A copy of Re:無事 is a directory with src/Version.php.
version_of() {
    sed -n "s/.*CURRENT = '\([^']*\)'.*/\1/p" "$1/src/Version.php"
}

if [ -z "$root" ] && [ -z "$public" ]; then
    if [ -n "$home" ] && [ -f "$home/re-buji/src/Version.php" ]; then
        root="$home/re-buji"
    else
        {
            echo "置き方を選んで、--public か --root を渡してください。"
            echo "  sh install.sh --public ~/www/anpi    本体を ~/re-buji に置き、公開ディレクトリには public/ の中身だけを置く（おすすめ）"
            echo "  sh install.sh --root ~/www/re-buji   全体を公開ディレクトリの中に置く（本体を外に置けないサーバー向け）"
        } >&2
        exit 2
    fi
elif [ -z "$root" ]; then
    [ -n "$home" ] || fail "HOME が設定されていないので、本体の場所を決められません。--root で渡してください。"
    root="$home/re-buji"
fi

# A place whose parent is missing is reported as that, unless it is plainly inside (or around) the other one.
overlap() {
    fail "--root（$1）と --public（$2）が重なっています。別の場所にしてください。"
}

given_root=$root
given_public=$public
root=$(absolute "$given_root") || root=""
[ -z "$public" ] || public=$(absolute "$given_public") || public=""

if [ -z "$root" ] && [ -n "$public" ]; then
    case "$given_root" in
        "$public"/*) overlap "$given_root" "$public" ;;
    esac
fi

[ -n "$root" ] || fail "$given_root の親のディレクトリがありません。"

if [ -z "$public" ] && [ -n "$given_public" ]; then
    case "$given_public" in
        "$root"/*) overlap "$root" "$given_public" ;;
    esac

    fail "$given_public の親のディレクトリがありません。"
fi

if [ -n "$public" ]; then
    case "$root/" in
        "$public"/*) overlap "$root" "$public" ;;
    esac

    case "$public/" in
        "$root"/*) overlap "$root" "$public" ;;
    esac
fi

continuing=""

if [ -e "$root" ]; then
    [ -d "$root" ] || fail "$root はディレクトリではありません。"

    if [ -f "$root/src/Version.php" ]; then
        if [ -f "$root/storage/config.php" ] && grep -q "'installed_at'" "$root/storage/config.php"; then
            fail "$root は設置を終えた Re:無事 です。上げるときは sh $root/bin/upgrade.sh を流してください。"
        fi

        continuing=1
    elif [ -n "$(ls -A "$root")" ]; then
        fail "$root は空ではありません（$(names "$root")）。まだ無いか、空のディレクトリを渡してください。"
    fi
fi

if [ -n "$public" ] && [ -e "$public" ]; then
    [ -d "$public" ] || fail "$public はディレクトリではありません。"

    if [ -n "$(ls -A "$public")" ]; then
        # Left by an earlier run of this same install: its rebuji-root.php points at this --root.
        if [ -f "$public/rebuji-root.php" ] && "$php" -r 'exit(is_string($r = require $argv[1]) && realpath($argv[2]) !== false && realpath($r) === realpath($argv[2]) ? 0 : 1);' "$public/rebuji-root.php" "$root" > /dev/null 2>&1; then
            :
        else
            fail "$public にほかのファイルがあります（$(names "$public")）。まだ無いか、空のディレクトリを渡してください。"
        fi
    fi
fi

if [ -n "$config" ]; then
    [ -f "$config" ] || fail "設定ファイルがありません：$config"
    given=$config
    config=$(absolute "$given") || fail "設定ファイルがありません：$given"
fi

if [ -n "$continuing" ]; then
    copy=$(version_of "$root")
    [ -f "$root/src/Console/InstallCommand.php" ] || fail "Re:無事 $copy は install.sh で入れられません。0.4.0 以降を選んでください。"
else
    command -v unzip > /dev/null 2>&1 || fail "unzip が見つかりません。"
    [ -n "$zip" ] || command -v curl > /dev/null 2>&1 || fail "curl が見つかりません。"
    [ -z "$zip" ] || [ -f "$zip" ] || fail "zip がありません：$zip"
fi

# --- the zip ---------------------------------------------------------------------------------------------------------------

sha256() {
    if command -v sha256sum > /dev/null 2>&1; then
        sha256sum "$1" | cut -d ' ' -f 1
    elif command -v shasum > /dev/null 2>&1; then
        shasum -a 256 "$1" | cut -d ' ' -f 1
    else
        openssl dgst -sha256 "$1" | sed 's/.*= *//'
    fi
}

if [ -n "$continuing" ]; then
    echo "$root の Re:無事 $copy の設定を続けます。"
else
    # Next to --root, on the same disk, so the new copy is moved into place in one mv. Removed on every way out.
    work="$(dirname -- "$root")/.re-buji-install-$$"
    mkdir -m 700 "$work" || fail "作業用のディレクトリを作れません：$work"
    trap 'rm -rf "$work"' EXIT
    trap 'exit 1' HUP INT TERM

    if [ -n "$zip" ]; then
        echo "zip を使います：$zip（sha256 $(sha256 "$zip")）"
        archive=$zip
    else
        archive="$work/release.zip"

        if [ -n "$version" ]; then
            api="https://api.github.com/repos/$repo/releases/tags/v$version"
        else
            api="https://api.github.com/repos/$repo/releases/latest"
        fi

        # Same as bin/upgrade.sh: change both together.
        curl -fsSL -H 'Accept: application/vnd.github+json' -o "$work/release.json" "$api" \
            || fail "GitHub からリリースの情報を取れませんでした：$api"

        # The tag, the zip's URL and its sha256, one per line. php is already needed here, so no jq.
        "$php" -r '
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
        zipurl=$(sed -n 2p "$work/release.txt")
        expected=$(sed -n 3p "$work/release.txt")

        # Only a version number, this repository's download URL for it and a 64-digit hex hash are taken from the answer.
        case "$latest" in
            ''|*[!0-9.]*) fail "リリースの版を読めません：$latest" ;;
        esac

        case "$zipurl" in
            "https://github.com/$repo/releases/download/v$latest/re-buji-$latest.zip") ;;
            *) fail "zip の URL が思っていたものと違うので、止めます：$zipurl" ;;
        esac

        case "$expected" in
            *[!0-9a-f]*|'') fail "GitHub が zip の sha256 を示していないので、止めます。" ;;
        esac

        [ ${#expected} -eq 64 ] || fail "GitHub が zip の sha256 を示していないので、止めます。"
        echo "Re:無事 $latest を $root に入れます。zip を取っています…"
        curl -fsSL --proto '=https' --proto-redir '=https' -o "$archive" "$zipurl" || fail "zip を取れませんでした：$zipurl"

        actual=$(sha256 "$archive")

        if [ "$actual" != "$expected" ]; then
            fail "zip の sha256 が GitHub の示すものと違うので、止めます（GitHub：$expected、取った zip：$actual）。"
        fi

        echo "✓ zip の sha256 を確かめました：$actual"
    fi

    unzip -q "$archive" -d "$work/unpacked" || fail "zip を展開できませんでした。"
    [ -f "$work/unpacked/re-buji/src/Version.php" ] || fail "zip の中に re-buji/ が見つかりません。"

    if [ ! -f "$work/unpacked/re-buji/src/Console/InstallCommand.php" ]; then
        fail "Re:無事 $(version_of "$work/unpacked/re-buji") は install.sh で入れられません。0.4.0 以降を選んでください。"
    fi

    # Checked above to be absent or empty; an empty directory has to go for mv to put the copy at its name.
    if [ -d "$root" ]; then
        rmdir "$root" || fail "$root を置き換えられません。"
    fi

    mv "$work/unpacked/re-buji" "$root" || fail "$root に置けません。"
    echo "✓ $root に展開しました"

    rm -rf "$work"
    trap - EXIT
fi

# --- hand over ---------------------------------------------------------------------------------------------------------------

set -- install
[ -z "$public" ] || set -- "$@" --public="$public"
[ -z "$url" ] || set -- "$@" --url="$url"
[ -z "$config" ] || set -- "$@" --config="$config"
set -- "$@" --php="$php"

exec "$php" "$root/bin/console" "$@"

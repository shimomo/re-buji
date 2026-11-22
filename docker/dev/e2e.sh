#!/bin/sh
# 手元の構成（テスト用メールサーバーつき）を立ち上げ、「テストメールを送る → 返信が来る → 読める」までを通しで確かめる。
# CI でも使う。終了コードは `bin/console check --wait-reply` のもの。
#
#   docker/dev/e2e.sh

set -u

cd "$(dirname "$0")/../.."

compose="docker compose -f compose.yaml -f compose.dev.yaml"
work=$(mktemp -d)

cleanup() {
    rm -rf "$work"
    $compose down -v >/dev/null 2>&1
}
trap cleanup EXIT

$compose up -d --build || exit 1

echo "app が動くのを待っています…"
tries=0
until [ "$($compose ps --format '{{.Health}}' app)" = "healthy" ]; do
    tries=$((tries + 1))
    if [ "$tries" -gt 90 ]; then
        echo "app が healthy になりませんでした。" >&2
        $compose logs app >&2
        exit 1
    fi
    sleep 2
done

printf '%s\n' 'e2e-password-12345' | $compose exec -T app php bin/console admin:create --name=テスト --email=admin@example.test || exit 1

# 返信を待つ check をバックグラウンドで流し、出力から message-id を拾う。
$compose exec -T app php bin/console check --to=admin@example.test --wait-reply --timeout=120 > "$work/check.log" 2>&1 &
check_pid=$!

messageId=""
tries=0
while [ -z "$messageId" ]; do
    messageId=$(sed -n 's/^message-id: //p' "$work/check.log" | head -n 1)
    [ -n "$messageId" ] && break
    if ! kill -0 "$check_pid" 2>/dev/null; then
        break
    fi
    tries=$((tries + 1))
    if [ "$tries" -gt 120 ]; then
        break
    fi
    sleep 1
done

if [ -z "$messageId" ]; then
    echo "check が message-id を出しませんでした。" >&2
    cat "$work/check.log" >&2
    kill "$check_pid" 2>/dev/null
    exit 1
fi

# check が送ったメールは anpi@example.test から admin@example.test へ。返信は admin から anpi へ。
$compose run --rm --no-deps -T \
    -v "$PWD/docker/dev/reply.php:/reply.php:ro" \
    app php /reply.php admin@example.test anpi@example.test "$messageId" || exit 1

wait "$check_pid"
status=$?
cat "$work/check.log"
exit "$status"

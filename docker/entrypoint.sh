#!/bin/sh
# docker/entrypoint.sh [web|cron|COMMAND...]
#
# web（既定）: 起動の準備（php bin/console boot）が通ったときだけ、ウェブを起動する。
# cron:        1 分ごとに bin/cron.php を動かす。重なりは CronRunner の storage/cron.lock が防ぐ。
# それ以外:    そのまま実行する。

set -eu

case "${1:-web}" in
    web)
        if [ -z "${SERVER_NAME:-}" ]; then
            echo "REBUJI_DOMAIN にドメインを書いてください。" >&2
            exit 1
        fi

        php bin/console boot || exit 1

        exec frankenphp run --config /etc/caddy/Caddyfile
        ;;
    cron)
        # boot は app が流す。それまでの回は bin/cron.php が何もせずに終わる。
        # 止めるとき（docker compose stop / down）は、流している回が終わるのを待ってから抜ける。メールを送っている途中で
        # 切らないため。待つあいだの sleep は裏で流し、wait で待つ（シグナルがすぐ届くように）。
        stopping=0
        trap 'stopping=1' TERM INT

        while [ "$stopping" -eq 0 ]; do
            php bin/cron.php || true

            [ "$stopping" -eq 0 ] || break

            sleep 60 &
            wait $! || true
            kill $! 2>/dev/null || true
        done

        exit 0
        ;;
    *)
        exec "$@"
        ;;
esac

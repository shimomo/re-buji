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
        while :; do
            php bin/cron.php || true
            sleep 60
        done
        ;;
    *)
        exec "$@"
        ;;
esac

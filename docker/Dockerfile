# Re:無事 のイメージ（ウェブと cron で共通）。FrankenPHP（Caddy 入りの PHP）の上で動かす。

# --- vendor/ を作る段 -------------------------------------------------------------------------------------------
FROM composer:2.11.0 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction --no-progress

COPY src ./src
COPY migrations ./migrations
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction

# --- 本体 ---------------------------------------------------------------------------------------------------------
FROM dunglas/frankenphp:1.13.4-php8.3-bookworm

RUN install-php-extensions pdo_sqlite pdo_mysql mbstring

ENV REBUJI_ENV_CONFIG=1

# root でないユーザーで動かす。setcap は、そのユーザーが 80 番と 443 番を開けるようにする。
RUN useradd --uid 1000 --user-group --no-create-home --shell /usr/sbin/nologin rebuji \
    && setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp \
    && mkdir -p /app/storage /data /config \
    && chown -R rebuji:rebuji /app /data /config

WORKDIR /app

COPY --chown=rebuji:rebuji bin ./bin
COPY --chown=rebuji:rebuji migrations ./migrations
COPY --chown=rebuji:rebuji public ./public
COPY --chown=rebuji:rebuji src ./src
COPY --chown=rebuji:rebuji templates ./templates
COPY --chown=rebuji:rebuji composer.json ./
COPY --chown=rebuji:rebuji --from=vendor /app/vendor ./vendor
COPY --chown=rebuji:rebuji storage/.htaccess ./storage/.htaccess

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

USER rebuji

# storage/ はボリュームにする（データベース、設定、ログ）。Caddy の証明書は /data と /config。
VOLUME ["/app/storage"]

# 内側の待ち受け（127.0.0.1:8081）に聞く。HTTPS の証明書がまだでも、アプリが動いているかを確かめられる。
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1:8081/login || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["web"]

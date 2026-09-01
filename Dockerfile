# syntax=docker/dockerfile:1

# One image, two roles. `web` serves the UI, `scheduler` runs the minute loop
# that actually makes this a monitor. Both are the same filesystem so there is
# no way for the UI and the checker to disagree about what the code says.

ARG FRANKENPHP_TAG=1-php8.4-alpine
ARG NODE_TAG=22-bookworm-slim

# --- base -------------------------------------------------------------------
# pdo_mysql talks to the monitored servers; the app's own store is SQLite,
# which is compiled into PHP already.
FROM dunglas/frankenphp:${FRANKENPHP_TAG} AS base

RUN install-php-extensions pdo_mysql opcache zip pcntl \
    && apk add --no-cache curl

WORKDIR /app

# --- composer dependencies --------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache git unzip

# Dependencies first, so a code-only change does not re-resolve the tree.
COPY composer.json composer.lock ./

# Two things here are about surviving a bad day at github.com rather than about
# the build itself:
#
#   `--prefer-install=auto` keeps dist archives as the fast path but lets a
#   package that will not download fall back to a git clone. `--prefer-dist`
#   turns that fallback off, so one HTTP 504 on an api.github.com zipball fails
#   the whole image.
#
#   COMPOSER_AUTH, when the builder passes it, authenticates those zipball
#   requests. Anonymous ones share a rate limit with everything else leaving the
#   runner. The secret is optional: without it the env var is simply unset and
#   Composer downloads anonymously, so a plain `docker build` still works.
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH \
    composer install --no-dev --no-interaction --no-progress \
        --prefer-install=auto --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer run-script post-autoload-dump

# --- frontend ---------------------------------------------------------------
# Debian rather than Alpine on purpose: package.json pins the *-linux-x64-gnu
# binaries for rollup, tailwind oxide and lightningcss, which do not run on musl.
FROM node:${NODE_TAG} AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# app.css @imports vendor/livewire/flux and @sources its stubs, so the CSS
# build genuinely needs the composer tree.
COPY --from=vendor /app/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# --- runtime ----------------------------------------------------------------
FROM base AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    REPL_DATA_DIR=/var/lib/repl-monitor

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/entrypoint.sh /usr/local/bin/repl-monitor-entrypoint

# /data and /config belong to Caddy, /var/lib/repl-monitor to us. Chowning the
# data dir in the image is what gives a fresh named volume the right owner.
RUN chmod +x /usr/local/bin/repl-monitor-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
        "${REPL_DATA_DIR}" \
    && chown -R www-data:www-data storage bootstrap/cache "${REPL_DATA_DIR}" /data /config

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["repl-monitor-entrypoint"]
CMD ["web"]

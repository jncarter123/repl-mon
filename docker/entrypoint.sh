#!/bin/sh
#
# Two roles out of one image:
#
#   web        Caddy/FrankenPHP over public/, plus the migrations.
#   scheduler  `schedule:work`, which is the cron line from the README with
#              nothing else on the box to run it.
#
# The web container owns the migrations and the key so the two never race; the
# scheduler waits for it (compose holds it back until /up answers).

set -e

role="${1:-web}"
data_dir="${REPL_DATA_DIR:-/var/lib/repl-monitor}"
key_file="${data_dir}/app_key"

mkdir -p "${data_dir}"

# --- APP_KEY ---------------------------------------------------------------
# It encrypts the stored database passwords. Losing it does not lock you out of
# the app, it makes every pair's credentials unreadable, so it is kept on the
# volume and loudly announced the once.
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -f "${key_file}" ] && [ "${role}" != "web" ]; then
        echo "repl-monitor: waiting for the web container to write ${key_file}" >&2
        waited=0
        while [ ! -f "${key_file}" ] && [ "${waited}" -lt 60 ]; do
            sleep 1
            waited=$((waited + 1))
        done
    fi

    if [ ! -f "${key_file}" ]; then
        php artisan key:generate --show > "${key_file}"
        chmod 600 "${key_file}"
        echo "repl-monitor: generated an APP_KEY in ${key_file}." >&2
        echo "repl-monitor: back up that volume — without this key the stored database passwords cannot be decrypted." >&2
    fi

    APP_KEY="$(cat "${key_file}")"
    export APP_KEY
fi

# --- the app's own store ---------------------------------------------------
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    : "${DB_DATABASE:=${data_dir}/database.sqlite}"
    export DB_DATABASE

    if [ ! -f "${DB_DATABASE}" ]; then
        touch "${DB_DATABASE}"
        chmod 600 "${DB_DATABASE}"
    fi
fi

# --- caches ----------------------------------------------------------------
# Built here rather than at image build time: the env is only complete now.
php artisan config:cache
php artisan view:cache

# A package that registers a closure route would make this fail; a missing
# route cache is a slower app, not a broken one, so it is not fatal.
if ! php artisan route:cache; then
    echo "repl-monitor: route:cache failed, continuing without a route cache" >&2
    php artisan route:clear
fi

case "${role}" in
    web)
        php artisan migrate --force
        exec frankenphp run --config /app/docker/Caddyfile
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    check)
        # One pass, for `docker compose run --rm scheduler check`.
        exec php artisan replication:check
        ;;
    *)
        exec "$@"
        ;;
esac

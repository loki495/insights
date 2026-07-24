#!/usr/bin/env bash
set -e

# `docker exec insights-app ...` calls that forget `-u www-data` run as root and leave root-owned
# files behind in these shared cache/temp dirs — which then permanently block later
# `-u www-data` commands (pest browser tests, artisan, phpstan, etc.) from writing there until
# manually chowned. Self-heal on every container start/restart instead of requiring that.
paths=(
    /tmp
    /var/www/html/storage
    /var/www/html/bootstrap/cache
    /var/www/html/vendor/pestphp/pest-plugin-browser/.temp
)

for path in "${paths[@]}"; do
    if [ -d "$path" ]; then
        chown -R www-data:www-data "$path" || true
    fi
done

exec docker-php-entrypoint "$@"

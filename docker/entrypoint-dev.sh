#!/usr/bin/env bash
set -e

# `docker exec insights-app ...` calls that forget `-u www-data` run as root and leave root-owned
# files behind in shared cache/temp dirs — which then permanently block later `-u www-data`
# commands (pest browser tests, artisan, phpstan, peck, coverage, etc.) from writing there until
# manually chowned. Self-heal on every container start/restart instead of requiring that.
#
# vendor/ is chowned wholesale rather than listing specific tool cache subdirectories
# (vendor/pestphp/pest-plugin-browser/.temp, vendor/pestphp/pest/.temp,
# vendor/peckphp/peck/.peck.cache, ...) — new tools/versions keep growing their own lazily-created
# cache dirs under vendor/, and chasing each one individually here doesn't scale. A full chown -R
# is a one-time per-boot cost, not a per-command one.
paths=(
    /tmp
    /var/www/html/storage
    /var/www/html/bootstrap/cache
    /var/www/html/vendor
)

for path in "${paths[@]}"; do
    if [ -d "$path" ]; then
        chown -R www-data:www-data "$path" || true
    fi
done

exec docker-php-entrypoint "$@"

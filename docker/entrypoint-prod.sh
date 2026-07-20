#!/usr/bin/env bash
set -e

# Laravel doesn't create a missing SQLite file on its own — migrate would just fail against a
# fresh volume on first run otherwise. Falls back to the same path Laravel itself defaults to
# (database_path('database.sqlite')) when DB_DATABASE isn't set in .env.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    db_path="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    if [ ! -f "$db_path" ]; then
        mkdir -p "$(dirname "$db_path")"
        touch "$db_path"
        chown www-data:www-data "$db_path"
    fi
fi

php artisan migrate --force

exec "$@"

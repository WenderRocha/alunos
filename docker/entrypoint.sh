#!/usr/bin/env bash

set -Eeuo pipefail

cd /var/www/html

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

if [[ "${RUN_PACKAGE_DISCOVERY:-true}" == "true" ]]; then
    php artisan package:discover --ansi
fi

if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
    php artisan migrate --force
fi

if [[ "${RUN_OPTIMIZE:-true}" == "true" ]]; then
    php artisan optimize
fi

if [[ "${1:-web}" != "web" ]]; then
    exec "$@"
fi

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf

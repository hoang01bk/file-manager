#!/bin/sh
set -eu

if [ "${LARAVEL_OPTIMIZE:-0}" = "1" ]; then
    cd /var/www
    mkdir -p storage/app/public storage/app/private storage/logs \
        storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
    # Uploaded files may have been created by older containers running as root.
    chown -R www-data:www-data storage/app storage/logs storage/framework bootstrap/cache
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    if [ "${1:-}" = "php-fpm" ]; then
        php artisan view:cache --no-interaction
    fi
    chown -R www-data:www-data bootstrap/cache storage/framework/views
fi

exec docker-php-entrypoint "$@"

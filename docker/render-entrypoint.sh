#!/usr/bin/env sh
set -eu

port="${PORT:-10000}"
sed -ri "s/^Listen [0-9]+/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p \
    storage/app/public \
    storage/app/updates \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${TABLEPLAY_MEDIA_DRIVER:-local}" = "local" ]; then
    php artisan storage:link --force >/dev/null 2>&1 || true
fi

exec "$@"

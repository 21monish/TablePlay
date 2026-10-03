#!/usr/bin/env sh
set -eu

php artisan migrate --force
php artisan db:seed --force

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/tableplay.conf

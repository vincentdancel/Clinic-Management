#!/usr/bin/env bash
set -e

PORT="${PORT:-8080}"
sed -ri "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

php artisan config:clear
php artisan migrate --force
php artisan storage:link || true

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec apache2-foreground

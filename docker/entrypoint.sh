#!/bin/sh
set -e

# Ensure storage directories exist on mounted volumes
mkdir -p /var/www/html/storage/logs \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/app/public \
         /var/www/html/bootstrap/cache

# Fix ownership and permissions for www-data
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Ensure public/storage symlink exists
if [ ! -L /var/www/html/public/storage ]; then
    rm -rf /var/www/html/public/storage
    ln -s /var/www/html/storage/app/public /var/www/html/public/storage
fi

exec "$@"

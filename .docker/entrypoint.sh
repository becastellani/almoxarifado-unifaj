#!/bin/sh
set -e

mkdir -p /var/www/storage/uploads/devolucao
chown -R www-data:www-data /var/www/storage
chmod -R 775 /var/www/storage

exec "$@"

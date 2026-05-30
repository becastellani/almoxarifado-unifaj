#!/bin/sh
set -e

mkdir -p /var/www/storage/uploads/devolucao
chown -R www-data:www-data /var/www/storage
chmod -R 775 /var/www/storage

# Railway injeta $PORT; fallback 80 para outros ambientes (Fly, local)
PORT=${PORT:-80}
sed -i "s/Listen 80/Listen ${PORT}/g"  /etc/apache2/ports.conf
sed -i "s/*:80>/*:${PORT}>/g"          /etc/apache2/sites-available/000-default.conf

exec "$@"

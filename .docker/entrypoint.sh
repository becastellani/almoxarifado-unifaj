#!/bin/sh
set -e

mkdir -p /var/www/storage/uploads/devolucao
chown -R www-data:www-data /var/www/storage
chmod -R 775 /var/www/storage

# Remove MPMs conflitantes (Railway pode ativar mpm_event por cima do prefork)
rm -f /etc/apache2/mods-enabled/mpm_event.load
rm -f /etc/apache2/mods-enabled/mpm_event.conf
rm -f /etc/apache2/mods-enabled/mpm_worker.load
rm -f /etc/apache2/mods-enabled/mpm_worker.conf

# Railway injeta $PORT; fallback 80 para outros ambientes (Fly, local)
PORT=${PORT:-80}
sed -i "s/Listen 80/Listen ${PORT}/g"  /etc/apache2/ports.conf
sed -i "s/*:80>/*:${PORT}>/g"          /etc/apache2/sites-available/000-default.conf

exec "$@"

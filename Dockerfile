FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && docker-php-ext-install pdo_sqlite

COPY .docker/apache.conf   /etc/apache2/sites-available/000-default.conf
COPY .docker/php.ini       /usr/local/etc/php/conf.d/security.ini
COPY .docker/entrypoint.sh /entrypoint.sh

COPY . /var/www/html/

RUN rm -rf /var/www/html/.docker \
           /var/www/html/.git \
           /var/www/html/.claude \
           /var/www/html/Dockerfile \
           /var/www/html/.dockerignore \
           /var/www/html/fly.toml \
           /var/www/html/iniciar.bat \
           /var/www/html/iniciar.command \
           /var/www/html/database \
    && chmod +x /entrypoint.sh \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]

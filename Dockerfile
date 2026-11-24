# BuildTrack container image (used by Render; works on any Docker host).
# Apache + PHP 8.3 with public/ as the web root. SQLite lives in /var/www/html/data.
FROM php:8.3-apache

# pdo_sqlite and fileinfo are built into the official image; add opcache for speed.
RUN docker-php-ext-install opcache \
 && a2enmod rewrite headers \
 && rm -f /etc/apache2/sites-enabled/000-default.conf

COPY docker/apache.conf /etc/apache2/sites-enabled/buildtrack.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-buildtrack.ini
# Render tells the app which port to use via $PORT (default 10000). Apache expands ${PORT} at start-up.
RUN sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf

WORKDIR /var/www/html
COPY . /var/www/html
RUN chmod +x docker/entrypoint.sh bin/console \
 && mkdir -p data && chown -R www-data:www-data data

# Safe defaults for a hosted instance; override in the Render dashboard / render.yaml.
ENV PORT=10000 \
    APP_ENV=production \
    TRUST_PROXY=true \
    LOG_STDERR=true

EXPOSE 10000
HEALTHCHECK --interval=30s --timeout=5s CMD php -r 'exit(@file_get_contents("http://127.0.0.1:" . getenv("PORT") . "/healthz.php") === "ok" ? 0 : 1);'
ENTRYPOINT ["docker/entrypoint.sh"]

FROM php:7.4-fpm-alpine

ENV FPM_PM_MAX_CHILDREN=5 \
    FPM_PM_START_SERVERS=2 \
    FPM_PM_MIN_SPARE_SERVERS=1 \
    FPM_PM_MAX_SPARE_SERVERS=3 \
    PHP_MAX_UPLOAD_SIZE=128m \
    PHP_MAX_EXECUTION_TIME=300 \
    PHP_MEMORY_LIMIT=512m

COPY --from=ochinchina/supervisord:latest /usr/local/bin/supervisord /usr/bin/supervisord
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
EXPOSE 80

RUN set -x && \
      apk add --no-cache \
      nginx \
      shadow \
      unzip \
      wget \
      sudo \
      bash && \
    install-php-extensions bcmath gd intl mysqli pdo_mysql sockets bz2 gmp soap zip gmp ffi redis opcache && \
    ln -s /usr/local/bin/php /usr/bin/php && \
    ln -sf /dev/stdout /var/log/nginx/access.log && \
    ln -sf /dev/stderr /var/log/nginx/error.log && \
    rm -rf /var/lib/nginx/tmp && \
    ln -sf /tmp /var/lib/nginx/tmp && \
    mkdir -p /var/tmp/nginx/ || true && \
    chown -R www-data:www-data /var/lib/nginx /var/tmp/nginx/ && \
    chmod 777 -R /var/tmp/nginx/ && \
    rm -rf /tmp/* && \
    chown -R www-data:www-data /var/www && \
    usermod -u 1000 www-data && \
    cd /var/www/html && \
    chown -R 1000 /var/www/html

COPY docker /

WORKDIR /var/www/html
COPY --chown=www-data . /var/www/html

RUN sudo -u www-data composer install
RUN APP_URL="http://localhost" sudo -u www-data -E bin/console assets:install && \
        rm -rf var/cache/*

ENTRYPOINT ["/entrypoint.sh"]

HEALTHCHECK --timeout=10s CMD curl --silent --fail http://127.0.0.1/fpm-ping

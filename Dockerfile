# ---------------------------------------------------------------------------
# Laravel application image (PHP-FPM) used by app-1/2/3 and the Horizon worker.
# Code AND vendor/ are bind-mounted at runtime (see docker-compose.yml), so this
# image only provides the PHP runtime + the extensions Laravel/Horizon need.
# No composer here on purpose (run `composer install` on the host) to keep the
# download/build minimal. linux-headers is only needed to compile pcntl and is
# removed afterwards.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine

RUN apk add --no-cache --virtual .build-deps linux-headers \
    && docker-php-ext-install pdo_mysql pcntl opcache \
    && apk del .build-deps

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]

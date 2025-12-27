FROM php:8.3-fpm-alpine

ARG WITH_ZIP=1

RUN apk add --no-cache postgresql-dev curl git \
    && docker-php-ext-install pdo pdo_pgsql pgsql pcntl \
    && if [ "$WITH_ZIP" = "1" ]; then apk add --no-cache libzip-dev; docker-php-ext-install zip; fi \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

WORKDIR /var/www

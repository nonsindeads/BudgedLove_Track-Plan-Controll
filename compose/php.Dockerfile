FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev curl git \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

WORKDIR /var/www

FROM php:8.3-fpm-alpine

ARG WITH_ZIP=1

RUN apk add --no-cache postgresql-dev sqlite-dev curl git \
    && docker-php-ext-install pdo pdo_pgsql pdo_sqlite pgsql pcntl \
    && if [ "$WITH_ZIP" = "1" ]; then apk add --no-cache libzip-dev; docker-php-ext-install zip; fi \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

RUN { \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=32M'; \
      echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/99-budgetlove-upload.ini

WORKDIR /var/www

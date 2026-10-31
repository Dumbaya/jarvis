FROM php:8.4-fpm-alpine

RUN apk add --no-cache git unzip libpq-dev icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql pgsql intl zip mbstring opcache pcntl bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY internal-pool.conf /usr/local/etc/php-fpm.d/internal.conf

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

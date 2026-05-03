FROM php:8.2-fpm-alpine

RUN apk add --no-cache bash git zip libzip-dev oniguruma-dev postgresql-dev icu-dev
RUN docker-php-ext-install intl pdo pdo_pgsql zip

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /var/www/html

RUN composer install --no-interaction --optimize-autoloader --no-dev

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]

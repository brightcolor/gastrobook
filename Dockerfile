# GastroBook – production-oriented PHP-FPM image
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        icu-dev libzip-dev libpng-dev postgresql-dev oniguruma-dev \
        zip unzip git \
    && docker-php-ext-install \
        intl zip gd pdo pdo_pgsql bcmath opcache pcntl \
    && apk add --no-cache --virtual .redis-deps autoconf g++ make \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .redis-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- Frontend build -----------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci
COPY resources resources
RUN npm run build

# --- Application --------------------------------------------------------
FROM base AS app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction

COPY . .
COPY --from=assets /app/public/build public/build

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm"]

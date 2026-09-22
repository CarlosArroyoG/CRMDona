# syntax=docker/dockerfile:1

# -----------------------------------------------------------------------------
# base: PHP + Apache con las extensiones que necesita la aplicación.
# -----------------------------------------------------------------------------
FROM php:8.5-apache AS base

ARG PHPREDIS_VERSION=6.3.0

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd bcmath exif pcntl \
    && pecl install redis-${PHPREDIS_VERSION} \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    TZ=America/Mexico_City

RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite headers \
    && printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/security-hardening.conf

COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# dev: imagen para desarrollo local; el código se monta como volumen.
# -----------------------------------------------------------------------------
FROM base AS dev

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# -----------------------------------------------------------------------------
# vendor: dependencias de Composer sin paquetes de desarrollo.
# -----------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# -----------------------------------------------------------------------------
# assets: compila CSS/JS de la página pública con Vite.
# -----------------------------------------------------------------------------
FROM node:24-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
COPY vite.config.js ./
COPY resources ./resources
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

# -----------------------------------------------------------------------------
# prod: imagen que despliega Coolify. La misma imagen sirve para app, worker y
# scheduler; cambia solo el comando (ver docs/tecnico/despliegue.md).
# -----------------------------------------------------------------------------
FROM base AS prod

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { echo "opcache.enable=1"; echo "opcache.validate_timestamps=0"; echo "opcache.memory_consumption=192"; } \
        > /usr/local/etc/php/conf.d/zz-opcache.ini

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/entrypoint

EXPOSE 8080

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]

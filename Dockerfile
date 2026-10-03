FROM composer:2 AS php_dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

FROM node:22-bookworm-slim AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

FROM php:8.3-apache-bookworm AS runtime

ENV APP_ENV=production \
    APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PORT=10000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
        postgresql-client \
        supervisor \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl mbstring pcntl pdo_mysql pdo_pgsql zip \
    && a2enmod expires headers rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .
COPY --from=php_dependencies /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/tableplay.conf
COPY docker/render-entrypoint.sh /usr/local/bin/tableplay-entrypoint
COPY docker/render-web-start.sh /usr/local/bin/tableplay-web-start

RUN mkdir -p \
        storage/app/public \
        storage/app/updates \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/tableplay-entrypoint /usr/local/bin/tableplay-web-start \
    && php artisan package:discover --ansi

EXPOSE 10000

ENTRYPOINT ["tableplay-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/tableplay.conf"]

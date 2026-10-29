FROM php:8.4-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libpq-dev unzip git \
 && docker-php-ext-install intl pdo_pgsql opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Couche de dépendances : rejouée seulement si composer.json / composer.lock changent.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist

# Code applicatif (vendor/ et var/ exclus via .dockerignore).
COPY . .
RUN composer dump-autoload --optimize

EXPOSE 8000

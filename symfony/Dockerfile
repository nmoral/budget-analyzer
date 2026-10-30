FROM php:8.4-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libpq-dev unzip git \
 # bcmath : arithmétique décimale exacte du VO de domaine Amount (#14).
 && docker-php-ext-install intl pdo_pgsql opcache bcmath \
 # pcov : driver de couverture requis par Infection (mutation testing, #12).
 && pecl install pcov \
 && docker-php-ext-enable pcov \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Utilisateur non-root dont l'UID/GID s'aligne sur l'hôte (défaut 1000, surchargeable
# au build via --build-arg). Sans lui, tout ce que le conteneur écrit sur le bind-mount
# ./symfony (var/cache, var/log, .php-cs-fixer.cache…) et dans le vendor/ de l'image
# sort en root:root sur l'hôte, ce qui rend les `git worktree remove` / nettoyages
# impossibles sans sudo. Avec un UID identique à celui de l'hôte, ces fichiers
# appartiennent directement à l'utilisateur.
ARG UID=1000
ARG GID=1000
RUN groupadd --gid ${GID} app \
 && useradd --uid ${UID} --gid ${GID} --create-home --shell /bin/bash app

WORKDIR /app
RUN chown app:app /app
USER app

# Couche de dépendances : rejouée seulement si composer.json / composer.lock changent.
COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist

# Code applicatif (vendor/ et var/ exclus via .dockerignore).
COPY --chown=app:app . .
RUN composer dump-autoload --optimize

EXPOSE 8000

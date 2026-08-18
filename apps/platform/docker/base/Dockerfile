# syntax=docker/dockerfile:1

# Shared build for every deployed environment. docker/staging/Dockerfile and
# docker/production/Dockerfile consume the final `app-base` stage through a
# buildx named context (see docker-bake.hcl), so the PHP version, the extension
# set and the asset build cannot drift between the two.
#
# The VITE_REVERB_* values are compiled into the JavaScript bundle and cannot be
# swapped at runtime, so this base is built once per environment invocation
# rather than shared across both.

FROM node:24-bookworm-slim AS node

FROM dunglas/frankenphp:php8.5-bookworm AS php-base

RUN install-php-extensions bcmath intl pcntl pdo_pgsql redis zip

FROM php-base AS build

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/ /usr/local/bin/
COPY --from=node /usr/local/lib/node_modules/ /usr/local/lib/node_modules/

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY . .

ARG REVERB_PUBLIC_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https

RUN composer run-script post-autoload-dump \
    && APP_ENV=local \
        VITE_REVERB_APP_KEY="$REVERB_PUBLIC_KEY" \
        VITE_REVERB_HOST="$VITE_REVERB_HOST" \
        VITE_REVERB_PORT="$VITE_REVERB_PORT" \
        VITE_REVERB_SCHEME="$VITE_REVERB_SCHEME" \
        npm run build \
    && rm -rf node_modules \
    && mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

FROM php-base AS app-base

WORKDIR /var/www/html

COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html

ENV XDG_CONFIG_HOME=/tmp/caddy/config
ENV XDG_DATA_HOME=/tmp/caddy/data

# Octane web and Reverb both bind 8080; they run as separate containers.
EXPOSE 8080

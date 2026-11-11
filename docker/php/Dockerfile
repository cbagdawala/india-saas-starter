# syntax=docker/dockerfile:1
FROM composer:2.8.4 AS composerbin

FROM node:22.23.3-bookworm-slim AS nodebin

FROM php:8.4.26-fpm-bookworm

ARG UID=501
ARG GID=20

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates libicu-dev libzip-dev libonig-dev \
    && docker-php-ext-install pdo_mysql pcntl intl zip bcmath opcache \
    && rm -rf /var/lib/apt/lists/*

# Node 22 + npm copied from the official image (only for the optional asset-build/vite services)
COPY --from=nodebin /usr/local/bin/node /usr/local/bin/node
COPY --from=nodebin /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY --from=composerbin /usr/bin/composer /usr/local/bin/composer

COPY opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Non-root user matching the host uid (GID 20 = macOS "staff"; may already exist as "dialout" in Debian)
RUN (getent group ${GID} >/dev/null || groupadd -g ${GID} app) \
    && useradd -m -u ${UID} -g ${GID} -s /bin/bash app \
    && sed -i "s/^user = .*/user = app/; s/^group = .*/group = $(getent group ${GID} | cut -d: -f1)/" /usr/local/etc/php-fpm.d/www.conf \
    && mkdir -p /var/www/html/node_modules && chown app:${GID} /var/www/html /var/www/html/node_modules

WORKDIR /var/www/html
USER app

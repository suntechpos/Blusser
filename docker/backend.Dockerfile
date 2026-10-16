FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM php:8.4-cli-bookworm@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b AS toolchain
RUN --mount=type=secret,id=build_ca \
    if [ -f /run/secrets/build_ca ]; then cp /run/secrets/build_ca /usr/local/share/ca-certificates/build-ca.crt; update-ca-certificates; fi \
    && sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get update -o APT::Update::Error-Mode=any && apt-get install -y --no-install-recommends libpq-dev libicu-dev libonig-dev libxml2-dev unzip git \
    && docker-php-ext-install -j2 pdo_pgsql intl mbstring dom xml \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
FROM toolchain AS development
WORKDIR /app
ARG COMPOSER_PREFER_INSTALL=dist
COPY backend/composer.json backend/composer.lock /app/
RUN --mount=type=cache,id=blusser-composer,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-interaction --prefer-install="$COMPOSER_PREFER_INSTALL" --no-progress --no-scripts \
    && find vendor -type d -name .git -prune -exec rm -rf {} +
COPY --chown=www-data:www-data backend/ /app/
COPY --chown=www-data:www-data docs/openapi.yaml /app/docs/openapi.yaml
RUN composer dump-autoload --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

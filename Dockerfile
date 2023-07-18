FROM php:8.2-cli-alpine as base

RUN docker-php-ext-install opcache \
    && docker-php-ext-enable opcache \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY opcache.ini $PHP_INI_DIR/conf.d/

WORKDIR /app

COPY composer.json /app/composer.json

FROM base as builder
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN composer install

FROM base
COPY --from=builder /app /app

COPY . /app

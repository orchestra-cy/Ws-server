FROM php:8.4-cli

WORKDIR /app

RUN apt-get update && apt-get install -y \
        git \
        unzip \
    && docker-php-ext-install pcntl posix \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

COPY bin/ /app/bin/
COPY config/ /app/config/

EXPOSE 8086 1234 2206

CMD ["php", "/app/bin/websocket-server.php", "start"]

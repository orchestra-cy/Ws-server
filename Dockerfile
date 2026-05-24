FROM php:8.4-cli

WORKDIR /app

RUN apt-get update && apt-get install -y \
        git \
        unzip \
    && docker-php-ext-install pcntl posix sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

COPY bin/ /app/bin/
COPY config/ /app/config/

# 1. Copy the startup script into the container
COPY start-websockets.sh /app/start-websockets.sh

# 2. Make the script executable
RUN chmod +x /app/start-websockets.sh

EXPOSE 8086 1234 2206

# 3. Change the CMD to run the bash script instead of the raw PHP file
CMD ["/app/start-websockets.sh"]
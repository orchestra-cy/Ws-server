FROM php:8.4-cli

WORKDIR /app

# Added dos2unix to handle potential Windows CRLF line endings
RUN apt-get update && apt-get install -y \
        git \
        unzip \
        dos2unix \
    && docker-php-ext-install pcntl posix sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# Explicitly copy only what the production environment needs
COPY bridge.php channel-server.php websocket-server.php /app/bin/
COPY config/ /app/config/
COPY start-websockets.sh /app/

# Convert line endings to Linux format and make executable
RUN dos2unix /app/start-websockets.sh && \
    chmod +x /app/start-websockets.sh

EXPOSE 8086 1234 2206

CMD ["/app/start-websockets.sh"]
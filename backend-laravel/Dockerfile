FROM php:8.3-cli-alpine

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libpq-dev \
    postgresql-client \
    sqlite-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_pgsql

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy application files
COPY . /app

# Install PHP production dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Ensure storage and cache permissions
RUN chmod -R 777 storage bootstrap/cache && chmod +x docker-entrypoint.sh

EXPOSE 8000

CMD ["sh", "docker-entrypoint.sh"]

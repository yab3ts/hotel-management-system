#!/bin/sh
set -e

echo "Starting Café X Backend on Railway/Production..."

# Run migrations automatically
echo "Running migrations..."
php artisan migrate --force

# Seed database if requested
if [ "$SEED_ON_BOOT" = "true" ]; then
    echo "Seeding database initial records..."
    php artisan db:seed --force || true
fi

# Use dynamic port provided by hosting environment ($PORT) or fallback to 8000
PORT="${PORT:-8000}"
echo "Serving application on port $PORT..."
exec php -S "0.0.0.0:$PORT" -t public

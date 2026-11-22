#!/bin/sh
set -e

echo "=== Starting Café X Backend on Railway/Production ==="

# If Railway provides DATABASE_URL, wire it to Laravel Postgres
if [ -n "$DATABASE_URL" ]; then
    echo "Configuring PostgreSQL from DATABASE_URL..."
    export DB_CONNECTION=pgsql
    export DB_URL="$DATABASE_URL"
else
    echo "No DATABASE_URL supplied; configuring SQLite fallback..."
    export DB_CONNECTION=sqlite
    export DB_DATABASE="/app/database/database.sqlite"
    mkdir -p /app/database
    touch /app/database/database.sqlite
fi

# Ensure APP_KEY exists so Laravel never crashes with 500
if [ -z "$APP_KEY" ]; then
    echo "Assigning default APP_KEY..."
    export APP_KEY="base64:3f4sR8K9X2yA1bC3dE5fG7hI9jK1lM3nO5pQ7rS9tU="
fi

# Enable DEMO_MODE by default for showcase unless explicitly set to false
export DEMO_MODE="${DEMO_MODE:-true}"

# Clear and cache configuration
php artisan config:clear || true

# Run database migrations
echo "Running migrations..."
php artisan migrate --force || echo "Warning: Migrations failed or database connection pending."

# Seed initial admin, tables, and demo records if requested or first run
if [ "$SEED_ON_BOOT" = "true" ] || [ ! -f /app/storage/.seeded ]; then
    echo "Seeding initial menu, tables, and credentials..."
    php artisan db:seed --force || true
    mkdir -p /app/storage && touch /app/storage/.seeded
fi

# Ensure storage directories permissions
chmod -R 777 /app/storage /app/bootstrap/cache /app/database || true

# Bind to Railway's dynamic PORT or default to 8000
PORT="${PORT:-8000}"
echo "Café X API is live and listening on 0.0.0.0:$PORT"
exec php -S "0.0.0.0:$PORT" -t public

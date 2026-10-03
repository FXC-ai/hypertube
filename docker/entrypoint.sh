#!/usr/bin/env bash

set -e

cd /var/www/html

echo "Preparing Laravel..."

mkdir -p \
    bootstrap/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

if [ ! -f .env ]; then
    echo "Creating .env..."
    cp .env.example .env
fi

echo "Installing PHP dependencies..."
composer install \
    --no-interaction \
    --prefer-dist

echo "Installing JavaScript dependencies..."
npm install \
    --no-audit \
    --no-fund

if ! grep -Eq '^APP_KEY=.+$' .env; then
    echo "Generating application key..."
    php artisan key:generate --force
fi

if grep -Eq '^DB_CONNECTION=sqlite$' .env \
    && [ ! -f database/database.sqlite ]; then
    echo "Creating SQLite database..."
    touch database/database.sqlite
fi

echo "Running migrations..."
php artisan migrate --force

echo "Starting Vite..."
npm run dev -- --host 0.0.0.0 &

echo "Starting queue worker..."
php artisan queue:work --tries=1 --timeout=0 &

echo "Starting PHP-FPM..."
exec php-fpm -F

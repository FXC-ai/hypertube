#!/usr/bin/env bash

set -e

cd /var/www/html
rm -f /tmp/hypertube-app-ready

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

if [ ! -f storage/oauth-private.key ]; then
    echo "Generating OAuth2 keys..."
    php artisan passport:keys
fi

if ! grep -Eq '^PASSPORT_API_CLIENT_ID=.+$' .env; then
    echo "Creating the OAuth2 client of the API..."
    php artisan tinker --execute '$client = (new \Laravel\Passport\ClientRepository)->createPasswordGrantClient("Hypertube API", provider: "users", confidential: true); file_put_contents(base_path(".env"), PHP_EOL . "PASSPORT_API_CLIENT_ID=" . $client->id . PHP_EOL . "PASSPORT_API_CLIENT_SECRET=" . $client->plainSecret . PHP_EOL, FILE_APPEND);' \
        || echo "Skipped: create it later with php artisan passport:client --password"
fi

echo "Generating the API documentation..."
php artisan l5-swagger:generate \
    || echo "Skipped: regenerate it later with php artisan l5-swagger:generate"

echo "Starting Vite..."
npm run dev -- --host 0.0.0.0 &

echo "Starting queue worker..."
php artisan queue:listen --tries=1 --timeout=0 &

echo "Starting PHP-FPM..."
touch /tmp/hypertube-app-ready
exec php-fpm -F

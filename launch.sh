#!/bin/bash
if [ ! -e ".env" ]; then
    echo "Missing .env file !"
    exit 1
fi

composer install

npm install

touch database/database.sqlite

php artisan migrate:fresh --seed

docker compose build
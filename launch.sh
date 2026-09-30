#!/bin/bash

if [ ! -e ".env" ]; then
    echo "Missing .env file !"
    exit 1
fi

composer install

npm install

touch database/database.sqlite

if [ ! -e "storage/app/public/movies/1/hls" ]; then
    rm -rf storage/app/public/movies/1/hls
fi

if [ ! -e "storage/app/public/movies/2/hls" ]; then
    rm -rf storage/app/public/movies/2/hls
fi

if [ ! -e "storage/app/public/movies/3/hls" ]; then
    rm -rf storage/app/public/movies/3/hls
fi

if [ ! -e "storage/app/public/movies/4/hls" ]; then
    rm -rf storage/app/public/movies/4/hls
fi

if [ ! -e "storage/app/public/movies/5/hls" ]; then
    rm -rf storage/app/public/movies/5/hls
fi

php artisan migrate:fresh --seed

php artisan test

docker compose build

docker compose up

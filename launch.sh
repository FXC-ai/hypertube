#!/bin/bash

if [ ! -e ".env" ]; then
    echo "Missing .env file !"
    exit 1
fi

#composer install

#npm install

#touch database/database.sqlite

#php artisan migrate:fresh --seed

#php artisan test

if [  -e "storage/app/public/movies/1/hls" ]; then
    rm -rf storage/app/public/movies/1/hls
fi

if [  -e "storage/app/public/movies/2/hls" ]; then
    rm -rf storage/app/public/movies/2/hls
fi

if [  -e "storage/app/public/movies/3/hls" ]; then
    rm -rf storage/app/public/movies/3/hls
fi

if [  -e "storage/app/public/movies/4/hls" ]; then
    rm -rf storage/app/public/movies/4/hls
fi

if [  -e "storage/app/public/movies/5/hls" ]; then
    rm -rf storage/app/public/movies/5/hls
fi


docker compose build

docker compose run --rm app touch database/database.sqlite

docker compose run --rm app php artisan migrate:fresh --seed

docker compose run --rm app php artisan test

docker compose up

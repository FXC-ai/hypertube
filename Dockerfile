FROM php:8.4-fpm

ARG WWW_USER=1000
ARG WWW_GROUP=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ffmpeg \
        git \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsqlite3-dev \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        exif \
        gd \
        mbstring \
        pcntl \
        pdo \
        pdo_sqlite \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid "${WWW_GROUP}" webapp \
    && useradd \
        --uid "${WWW_USER}" \
        --gid "${WWW_GROUP}" \
        --create-home \
        --shell /bin/bash \
        webapp

WORKDIR /var/www/html

USER webapp
FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    curl \
    ffmpeg \
    git \
    libsqlite3-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install \
        pdo_sqlite \
        pcntl \
        bcmath \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt-get install -y nodejs && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

COPY .env .env

RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["php-fpm", "-F"]
FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    curl \
    ffmpeg \
    git \
    libsqlite3-dev \
    libzip-dev \
    unzip \
    libfreetype6-dev \
    libjpeg-dev \
    libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_sqlite \
        pcntl \
        bcmath \
        zip \
        gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt-get install -y nodejs && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

COPY composer.json composer.json
COPY composer.lock composer.lock
COPY package.json package.json
COPY package-lock.json package-lock.json
COPY . .

RUN composer install
RUN npm ci


RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["php-fpm", "-F"]
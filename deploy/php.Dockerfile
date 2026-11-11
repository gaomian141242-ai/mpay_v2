FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev libfreetype6-dev libjpeg62-turbo-dev \
        libpng-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath gd mbstring pcntl pdo_mysql sockets xml zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app
RUN mkdir -p /app/runtime
EXPOSE 8787
CMD ["php", "start.php", "start"]

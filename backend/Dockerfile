FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev libonig-dev unzip git \
    && docker-php-ext-install -j2 pdo_mysql intl zip mbstring bcmath opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN printf 'upload_max_filesize=500M\npost_max_size=505M\nmemory_limit=512M\nmax_execution_time=600\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/cms.ini
WORKDIR /var/www/html

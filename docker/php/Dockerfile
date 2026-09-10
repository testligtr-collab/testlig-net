FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libxml2-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        intl \
        pdo_mysql \
        opcache \
        zip \
        bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-testlig.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/zz-www.conf /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /var/www/html

CMD ["php-fpm"]

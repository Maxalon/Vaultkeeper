FROM php:8.4-fpm

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring bcmath zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create a host-matching user so bind-mounted source files are writable
# from inside the container (rootless podman maps host UID 1000 → container UID 1000).
RUN groupadd -g ${GID} app \
    && useradd -m -u ${UID} -g ${GID} -s /bin/bash app

# php-fpm pool runs as the same UID as the bind-mounted source on the host.
RUN sed -ri "s|^user = .*|user = app|; s|^group = .*|group = app|" /usr/local/etc/php-fpm.d/www.conf \
    && sed -ri "s|^listen.owner = .*|listen.owner = app|; s|^listen.group = .*|listen.group = app|" /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

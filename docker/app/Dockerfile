FROM dunglas/frankenphp:php8.4-bookworm

# Composer bin
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Paquetes del SO + extensiones PHP típicas de Laravel + curl para healthcheck
RUN apt-get update && apt-get install -y git unzip curl libicu-dev libpq-dev libzip-dev \
     && install-php-extensions \
          pcntl intl zip opcache gd pdo_pgsql bcmath redis \
     && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Config PHP
COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-custom.ini

# Copiamos el proyecto
COPY . /app

# Entrypoint para preparar y lanzar Octane/FrankenPHP
RUN chmod +x /app/docker/app/entrypoint.sh
CMD ["/app/docker/app/entrypoint.sh"]

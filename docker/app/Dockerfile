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

# Copiar entrypoint (el código del proyecto se monta como volumen en desarrollo)
COPY docker/app/entrypoint.sh /app/docker/app/entrypoint.sh
RUN chmod +x /app/docker/app/entrypoint.sh

# Ejecutar como usuario no privilegiado por seguridad
USER www-data

CMD ["/app/docker/app/entrypoint.sh"]

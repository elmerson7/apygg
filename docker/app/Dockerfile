FROM dunglas/frankenphp:php8.4-bookworm

# Composer bin
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Paquetes del SO + extensiones PHP típicas de Laravel + curl para healthcheck
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        libicu-dev \
        libpq-dev \
        libzip-dev \
    && install-php-extensions \
        pcntl \
        intl \
        zip \
        opcache \
        gd \
        pdo_pgsql \
        bcmath \
        redis \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Config PHP
COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-custom.ini

# Crear usuario con mismo UID/GID del host para evitar problemas de permisos
# Esto permite que los archivos creados por el contenedor sean editables desde el IDE
ARG USER_ID=1000
ARG GROUP_ID=1000
RUN groupadd -g ${GROUP_ID} appuser || true && \
    useradd -u ${USER_ID} -g ${GROUP_ID} -m -s /bin/bash appuser || true && \
    usermod -aG www-data appuser || true

# Instalar gosu para cambiar de usuario de forma segura
RUN apt-get update && apt-get install -y gosu && rm -rf /var/lib/apt/lists/*

# Copiar entrypoint y wrapper (el código del proyecto se monta como volumen en desarrollo)
COPY docker/app/entrypoint.sh /app/docker/app/entrypoint.sh
COPY docker/app/entrypoint-wrapper.sh /app/docker/app/entrypoint-wrapper.sh
RUN chmod +x /app/docker/app/entrypoint.sh /app/docker/app/entrypoint-wrapper.sh

# Ejecutar como root para poder ajustar permisos, luego cambiar a appuser
USER root

CMD ["/app/docker/app/entrypoint-wrapper.sh"]

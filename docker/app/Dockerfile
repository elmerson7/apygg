FROM dunglas/frankenphp:1.13.6-php8.5.13-bookworm

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
        wget \
        ca-certificates \
        gnupg \
        fontconfig \
        libfreetype6 \
        libjpeg62-turbo \
        libpng16-16 \
        libx11-6 \
        libxcb1 \
        libxext6 \
        libxrender1 \
        xfonts-75dpi \
        xfonts-base \
    && echo "deb http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc | apt-key add - \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-18 \
    && install-php-extensions \
        pcntl \
        intl \
        zip \
        opcache \
        gd \
        pdo_pgsql \
        bcmath \
        redis \
        pcov \
    && rm -rf /var/lib/apt/lists/* /etc/apt/sources.list.d/pgdg.list

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

# Instalar gosu para cambio de usuario seguro (descarga directa, no está en repos)
RUN wget -q -O /usr/local/bin/gosu https://github.com/tianon/gosu/releases/download/1.17/gosu-amd64 \
    && chmod +x /usr/local/bin/gosu

# Copiar entrypoint y wrapper (el código del proyecto se monta como volumen en desarrollo)
COPY docker/app/entrypoint.sh /app/docker/app/entrypoint.sh
COPY docker/app/entrypoint-wrapper.sh /app/docker/app/entrypoint-wrapper.sh
RUN chmod +x /app/docker/app/entrypoint.sh /app/docker/app/entrypoint-wrapper.sh

# Copiar código del proyecto (para CI/producción)
# En desarrollo, esto se sobrescribe con el volumen montado
COPY composer.json composer.lock ./

# Instalar dependencias (incluyendo dev para CI, se puede sobrescribir con build arg)
ARG INSTALL_DEV_DEPS=true
RUN if [ "$INSTALL_DEV_DEPS" = "true" ]; then \
        composer install --no-interaction --prefer-dist --no-scripts --no-autoloader; \
    else \
        composer install --no-interaction --prefer-dist --no-dev --no-scripts --no-autoloader; \
    fi || true

COPY . .

# Completar instalación de Composer si hay autoloader
RUN if [ -f composer.json ]; then composer dump-autoload --no-interaction --optimize || true; fi

# .dockerignore excluye storage/framework/* y logs: crearlos para que `docker run <imagen> php artisan ...` arranque
# sin pasar por el entrypoint (en desarrollo el volumen montado ya los trae)
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Ajustar permisos del código copiado
RUN chown -R appuser:appuser /app || true

# Ejecutar como root para poder ajustar permisos, luego cambiar a appuser
USER root

CMD ["/app/docker/app/entrypoint-wrapper.sh"]

FROM dunglas/frankenphp:1.11-php8.5-bookworm

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

# Instalar gosu para cambiar de usuario de forma segura
RUN apt-get update && apt-get install -y gosu && rm -rf /var/lib/apt/lists/*

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

# Ajustar permisos del código copiado
RUN chown -R appuser:appuser /app || true

# Ejecutar como root para poder ajustar permisos, luego cambiar a appuser
USER root

CMD ["/app/docker/app/entrypoint-wrapper.sh"]

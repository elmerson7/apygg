#!/usr/bin/env bash
set -e
cd /app

# Instalar vendors si faltan
if [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
fi

# Generar APP_KEY si falta
if ! grep -q ^APP_KEY= .env || [ -z "$(grep ^APP_KEY= .env | cut -d= -f2)" ]; then
    php artisan key:generate
fi

# Cache/optimize (seguro aunque falten algunos paquetes aún)
# Limpia caches en dev para ver cambios al instante
if [ "$APP_ENV" = "dev" ]; then
    php artisan optimize:clear || true
else
    php artisan optimize || true
fi

# Iniciar Octane con FrankenPHP
if [ "$APP_ENV" = "dev" ]; then
    exec php artisan octane:start \
        --server=frankenphp \
        --host=0.0.0.0 \
        --port=8000 \
        --workers=auto \
        --watch
else
    exec php artisan octane:start \
        --server=frankenphp \
        --host=0.0.0.0 \
        --port=8000 \
        --workers=auto
fi
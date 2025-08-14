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
php artisan optimize || true

# Iniciar Octane con FrankenPHP
exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --workers=auto

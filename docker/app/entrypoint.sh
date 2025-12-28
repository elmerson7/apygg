#!/usr/bin/env bash
set -e
cd /app

# Verificar si Laravel está instalado (composer.json existe)
if [ ! -f composer.json ]; then
    echo "Laravel no está instalado aún. Esperando instalación..."
    echo "Para instalar Laravel, ejecuta: docker compose exec app composer create-project laravel/laravel ."
    # Mantener el contenedor corriendo para permitir instalación manual
    exec tail -f /dev/null
fi

# Instalar vendors si faltan
if [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
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
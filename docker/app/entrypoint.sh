#!/usr/bin/env bash
set -e
cd /app

# Ajustar permisos de archivos críticos para que sean editables desde el IDE y el contenedor
# Esto asegura que storage y bootstrap/cache sean escribibles por el usuario del contenedor
if [ -d "storage" ] && [ -d "bootstrap/cache" ]; then
    # Ajustar permisos de storage y cache
    # Usar sudo si está disponible, sino intentar sin sudo (puede fallar si no hay permisos)
    if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        sudo chown -R $(id -u):$(id -g) storage bootstrap/cache 2>/dev/null || true
        sudo find storage bootstrap/cache -type d -exec chmod 775 {} + 2>/dev/null || true
        sudo find storage bootstrap/cache -type f -exec chmod 664 {} + 2>/dev/null || true
    else
        # Intentar sin sudo (puede funcionar si el usuario tiene permisos)
        chown -R $(id -u):$(id -g) storage bootstrap/cache 2>/dev/null || true
        find storage bootstrap/cache -type d -exec chmod 775 {} + 2>/dev/null || true
        find storage bootstrap/cache -type f -exec chmod 664 {} + 2>/dev/null || true
    fi
fi

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
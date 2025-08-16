#!/usr/bin/env bash
set -e
cd /app

# Instalar vendors si faltan
if [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
fi

# Generar APP_KEY si falta
if [ -z "${APP_KEY:-}" ]; then
    if [ "${APP_ENV:-dev}" = "dev" ]; then
        APP_KEY="$(php artisan key:generate --show 2>/dev/null || true)"
        if [ -n "$APP_KEY" ]; then
            export APP_KEY
            echo "[dev] APP_KEY generado en memoria."
        else
            echo "ERROR: No se pudo generar APP_KEY en dev. Revisa vendor y permisos."
            exit 1
        fi
    else
        echo "ERROR: APP_KEY no está definido. Inyéctalo vía env_file (staging/prod)."
        exit 1
    fi
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
#!/usr/bin/env bash
set -e
cd /app

# Copiar archivo .env de Laravel si no existe
# NOTA: env/dev.env debe existir ANTES de ejecutar docker compose up
# porque Docker Compose lo lee antes de iniciar el contenedor
if [ ! -f .env ] && [ -f .env.example ]; then
    echo "Copiando .env.example → .env..."
    cp .env.example .env
fi

# Verificar si Laravel está instalado (composer.json existe)
if [ ! -f composer.json ]; then
    echo "Laravel no está instalado aún. Esperando instalación..."
    echo "Para instalar Laravel, ejecuta: docker compose exec app composer create-project laravel/laravel . --prefer-dist --no-interaction"
    # Mantener el contenedor corriendo para permitir instalación manual
    exec tail -f /dev/null
fi

# Instalar vendors si faltan o si autoload.php no existe
if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
    echo "Instalando dependencias de Composer..."
    composer install --no-interaction --prefer-dist
fi

# Ajustar permisos de ejecución para binarios de vendor (pest, pint, phpstan, etc.)
if [ -d "vendor/bin" ]; then
    echo "Ajustando permisos de ejecución para binarios de vendor..."
    chmod +x vendor/bin/* 2>/dev/null || true
fi

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

# Cache/optimize según entorno
if [ "$APP_ENV" = "dev" ] || [ "$APP_ENV" = "local" ] || [ "$APP_ENV" = "development" ]; then
    # En desarrollo: limpiar caches para ver cambios al instante
    echo "Entorno de desarrollo detectado, limpiando caches..."
    php artisan optimize:clear 2>&1 || echo "Warning: optimize:clear failed, continuing anyway..."
else
    # En producción: optimizar autoloader y caches
    echo "Entorno de producción detectado, optimizando..."
    
    # Optimizar autoloader de Composer (mejora rendimiento)
    echo "Optimizando autoloader de Composer..."
    composer dump-autoload -o --no-interaction 2>&1 || echo "Warning: composer dump-autoload -o failed, continuing anyway..."
    
    # Optimizar Laravel (cache de config, routes, views)
    echo "Optimizando caches de Laravel..."
    php artisan optimize 2>&1 || echo "Warning: optimize failed, continuing anyway..."
fi

# Configuración de puerto (dinámico para PaaS, fijo por defecto)
PORT=${PORT:-8000}
echo "Using port: $PORT"

# Iniciar servidor
# Intentar usar Octane con FrankenPHP primero, fallback al servidor PHP integrado
if [ "$APP_ENV" = "dev" ]; then
    echo "Starting Laravel Octane server (FrankenPHP) in development mode..."
    php artisan octane:start \
        --server=frankenphp \
        --host=0.0.0.0 \
        --port=$PORT \
        --workers=2 \
        --watch || {
        echo "Octane falló, usando servidor PHP integrado..."
        exec php -S 0.0.0.0:$PORT -t public public/index.php
    }
else
    # En producción, verificar si se debe usar SSL con Caddy
    if [ "$USE_FRANKENPHP_SSL" = "true" ] && [ -n "$SERVER_NAME" ]; then
        echo "Starting FrankenPHP with Caddy (SSL enabled) for: $SERVER_NAME"
        # Caddy manejará SSL automáticamente con Let's Encrypt
        # FrankenPHP escuchará en el puerto interno
        export CADDY_SERVER_NAME=$SERVER_NAME
        export CADDY_INTERNAL_PORT=$PORT
        exec caddy run --config /app/docker/app/Caddyfile
    else
        # En producción sin SSL en FrankenPHP (proxy externo maneja SSL)
        echo "Starting Laravel Octane server (FrankenPHP) in production mode..."
        exec php artisan octane:start \
            --server=frankenphp \
            --host=0.0.0.0 \
            --port=$PORT \
            --workers=auto || {
            echo "Octane falló, usando servidor PHP integrado..."
            exec php -S 0.0.0.0:$PORT -t public public/index.php
        }
    fi
fi
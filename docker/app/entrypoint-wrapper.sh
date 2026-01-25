#!/bin/bash
set -e
cd /app

# Ajustar permisos de vendor si existe (necesario para que Composer pueda escribir)
if [ -d "vendor" ]; then
    chown -R appuser:appuser vendor 2>/dev/null || true
    find vendor -type d -exec chmod 775 {} + 2>/dev/null || true
    find vendor -type f -exec chmod 664 {} + 2>/dev/null || true
fi

# Ajustar permisos de storage y bootstrap/cache si existen
if [ -d "storage" ] && [ -d "bootstrap/cache" ]; then
    chown -R appuser:appuser storage bootstrap/cache 2>/dev/null || true
    find storage bootstrap/cache -type d -exec chmod 775 {} + 2>/dev/null || true
    find storage bootstrap/cache -type f -exec chmod 664 {} + 2>/dev/null || true
fi

# Cambiar al usuario appuser y ejecutar entrypoint
exec gosu appuser /app/docker/app/entrypoint.sh "$@"


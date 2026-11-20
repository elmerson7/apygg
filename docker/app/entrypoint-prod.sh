#!/usr/bin/env bash
# Entrypoint de la imagen de producción: cachea config/rutas/vistas con el entorno REAL del
# contenedor y ejecuta el comando (octane por defecto; horizon, schedule:work, migrate... por override).
set -e
cd /app

if [ "${SKIP_OPTIMIZE:-0}" != "1" ]; then
    php artisan optimize >/dev/null 2>&1 || echo "Aviso: 'artisan optimize' falló; se sigue sin caché de config" >&2
fi

exec "$@"

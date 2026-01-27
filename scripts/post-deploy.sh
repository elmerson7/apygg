#!/bin/bash

# Script de post-deployment para APYGG
# Ejecutar después de cada deployment para asegurar que el sistema esté listo

set -e

echo "🚀 Iniciando tareas post-deployment..."

# 1. Limpiar cache de configuración
echo "📦 Limpiando cache de configuración..."
php artisan config:clear
php artisan cache:clear

# 2. Optimizar para producción (si aplica)
if [ "$APP_ENV" = "production" ]; then
    echo "⚡ Optimizando para producción..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# 3. Ejecutar migraciones pendientes
echo "🗄️  Ejecutando migraciones..."
php artisan migrate --force

# 4. Cache warming - Pre-cargar datos frecuentes
echo "🔥 Pre-calentando cache..."
php artisan cache:warm

# 5. Sincronizar índices de búsqueda (si aplica)
if php artisan list | grep -q "search:sync-indexes"; then
    echo "🔍 Sincronizando índices de búsqueda..."
    php artisan search:sync-indexes
fi

echo "✅ Post-deployment completado exitosamente"

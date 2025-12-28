#!/usr/bin/env bash
# Script para corregir permisos de archivos creados por Docker
# Uso: ./docker/app/fix-permissions.sh

set -e

# Obtener UID/GID del usuario actual del host
USER_ID=$(id -u)
GROUP_ID=$(id -g)

echo "Ajustando permisos con UID: $USER_ID, GID: $GROUP_ID"

# Cambiar propietario de todos los archivos del proyecto
sudo chown -R ${USER_ID}:${GROUP_ID} .

# Ajustar permisos de directorios y archivos
find . -type d -exec chmod 775 {} +
find . -type f -exec chmod 664 {} +

# Permisos especiales para scripts ejecutables
find . -name "*.sh" -exec chmod +x {} +
chmod +x artisan

echo "Permisos ajustados correctamente"


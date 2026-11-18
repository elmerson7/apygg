# Aplicación Laravel: comandos dentro del contenedor, claves, servicios y utilidades (targets: make/*.mk)
# Ejecutar comando arbitrario en contenedor app
exec:
	$(DC) exec app $(cmd)

# Abrir shell bash en app
sh:
	$(DC) exec app bash

# Ejecutar comando composer
composer:
	$(DC) exec app composer $(cmd)

# Ejecutar comando artisan
art:
	$(DC) exec app php artisan $(cmd)

# Generar APP_KEY de Laravel
key:
	@$(DC) exec app php artisan key:generate --show

# Generar clave JWT
jwt:
	@$(DC) exec app php artisan jwt:secret -f --show

# Generar clave Meilisearch
meilisearch-key:
	@KEY=$$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-32); \
	echo "MEILISEARCH_KEY=$$KEY"

# Reiniciar Horizon (colas)
horizon:
	$(DC) exec horizon php artisan horizon:terminate || true

# Reiniciar Reverb (WebSockets)
reverb:
	$(DC) exec reverb php artisan reverb:restart || true

# Recargar Octane sin downtime
octane:
	$(DC) exec app php artisan octane:reload || true

# Limpiar caches
clear:
	$(DC) exec app php artisan optimize:clear

# Crear symlink storage
storage-link:
	$(DC) exec app php artisan storage:link

# Verificar CORS
cors-check:
	$(DC) exec app php artisan cors:check --fix

# Corregir permisos de archivos creados por Docker
fix-permissions:
	@echo "Corrigiendo permisos con UID: $(USER_ID), GID: $(GROUP_ID)"
	sudo chown -R $(USER_ID):$(GROUP_ID) .
	find . -type d -exec chmod 775 {} + 2>/dev/null || true
	find . -type f -exec chmod 664 {} + 2>/dev/null || true
	find . -name "*.sh" -exec chmod +x {} + 2>/dev/null || true
	chmod +x artisan 2>/dev/null || true


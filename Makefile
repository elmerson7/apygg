ENV ?= dev
DC := docker compose --project-name apygg --profile $(ENV)

# Detectar UID/GID del usuario actual del host para permisos correctos
USER_ID ?= $(shell id -u)
GROUP_ID ?= $(shell id -g)

export USER_ID
export GROUP_ID

.PHONY: build up down logs ps sh composer art key migrate seed jwt scout horizon reverb tinker fix-permissions ensure-env

# Asegurar que env/${ENV}.env existe antes de build/up
ensure-env:
	@if [ ! -f env/$(ENV).env ]; then \
		if [ -f env/$(ENV).env.example ]; then \
			echo "Copiando env/$(ENV).env.example → env/$(ENV).env..."; \
			cp env/$(ENV).env.example env/$(ENV).env; \
		else \
			echo "Error: env/$(ENV).env.example no existe"; \
			exit 1; \
		fi; \
	fi

build: ensure-env
	USER_ID=$(USER_ID) GROUP_ID=$(GROUP_ID) $(DC) build --build-arg USER_ID=$(USER_ID) --build-arg GROUP_ID=$(GROUP_ID)

up: ensure-env
	APP_ENV=$(ENV) $(DC) up -d

down:
	APP_ENV=$(ENV) $(DC) down

logs:
	$(DC) logs -f --tail=200

ps:
	$(DC) ps

sh:
	$(DC) exec app bash

composer:
	$(DC) exec app composer $(cmd)

art:
	$(DC) exec app php artisan $(cmd)

key:
	@echo "Generando clave de aplicación..."
	$(DC) exec app php artisan key:generate --show
	@echo "Clave generada, guardala en env/$(ENV).env"

migrate:
	$(DC) exec app php artisan migrate --force

seed:
	$(DC) exec app php artisan db:seed --force

jwt:
	@echo "Generando clave JWT..."
	$(DC) exec app php artisan jwt:secret -f --show
	@echo "Clave JWT generada, guardala en env/$(ENV).env"

# Solo sync configuración
scout-sync:
	$(DC) exec app php artisan scout:manage sync --force

# Importar todos los modelos
scout-import:
	$(DC) exec app php artisan scout:manage import --force

# Limpiar todos los índices
scout-flush:
	$(DC) exec app php artisan scout:manage flush --force

# Resetear todos los índices
scout-reset:
	$(DC) exec app php artisan scout:manage reset --force

# Comando legacy para compatibilidad
scout:
	$(DC) exec app php artisan scout:sync-index-settings

# Verificar configuración CORS
cors-check:
	$(DC) exec app php artisan cors:check --fix

horizon:
	$(DC) exec horizon php artisan horizon:terminate || true

reverb:
	$(DC) exec reverb php artisan reverb:restart || true

# Corregir permisos de archivos creados por Docker
fix-permissions:
	@echo "Corrigiendo permisos con UID: $(USER_ID), GID: $(GROUP_ID)"
	sudo chown -R $(USER_ID):$(GROUP_ID) .
	find . -type d -exec chmod 775 {} + 2>/dev/null || true
	find . -type f -exec chmod 664 {} + 2>/dev/null || true
	find . -name "*.sh" -exec chmod +x {} + 2>/dev/null || true
	chmod +x artisan 2>/dev/null || true
	@echo "Permisos corregidos correctamente"

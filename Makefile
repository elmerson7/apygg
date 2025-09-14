ENV ?= dev
DC := docker compose --project-name apygg --profile $(ENV)

.PHONY: build up down logs ps sh composer art key migrate seed jwt scout horizon reverb tinker

build:
	$(DC) build

up:
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

horizon:
	$(DC) exec horizon php artisan horizon:terminate || true

reverb:
	$(DC) exec reverb php artisan reverb:restart || true

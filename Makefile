ENV ?= dev
USER_ID ?= $(shell id -u)
GROUP_ID ?= $(shell id -g)

PROFILES  = $(if $(filter prod,$(ENV)),--profile prod)
PROFILES += $(if $(filter dev,$(ENV)),--profile dev)
PROFILES += $(if $(SEARCH),--profile search)

DC := docker compose $(PROFILES) --env-file .env

export USER_ID
export GROUP_ID

.DEFAULT_GOAL := help

.PHONY: build up down stop restart redeploy logs ps sh exec composer art key migrate seed schema jwt meilisearch-key scout flint test test-filter test-watch test-parallel test-coverage pint pint-test phpstan horizon reverb octane-reload clear storage-link cors-check help

check-env:
	@if [ ! -f .env ]; then \
		echo "Creando .env desde .env.example..."; \
		cp .env.example .env; \
	fi

build: check-env
	USER_ID=$(USER_ID) GROUP_ID=$(GROUP_ID) $(DC) build

up: check-env
	$(DC) up -d

down:
	$(DC) down

stop:
	$(DC) stop

start:
	$(DC) start

restart:
	$(DC) restart $(service)

redeploy:
	$(DC) up -d --force-recreate app

logs:
	$(DC) logs -f --tail=200

ps:
	$(DC) ps

exec:
	$(DC) exec app $(cmd)

sh:
	$(DC) exec app bash

composer:
	$(DC) exec app composer $(cmd)

art:
	$(DC) exec app php artisan $(cmd)

key:
	@$(DC) exec app php artisan key:generate --show

migrate:
	$(DC) exec app php artisan migrate --force

seed:
	$(DC) exec app php artisan db:seed --force

schema:
	$(DC) exec app php artisan db:schema-dump

jwt:
	@$(DC) exec app php artisan jwt:secret -f --show

meilisearch-key:
	@KEY=$$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-32); \
	echo "MEILISEARCH_KEY=$$KEY"

test:
	$(DC) exec app composer test

test-filter:
	$(DC) exec app ./vendor/bin/pest --filter='$(filter)'

test-watch:
	$(DC) exec app composer test:watch

test-parallel:
	$(DC) exec app composer test:parallel

test-coverage:
	$(DC) exec app ./vendor/bin/pest --coverage

pint:
	$(DC) exec app ./vendor/bin/pint

pint-test:
	$(DC) exec app ./vendor/bin/pint --test

phpstan:
	$(DC) exec app ./vendor/bin/phpstan analyse

horizon:
	$(DC) exec horizon php artisan horizon:terminate || true

reverb:
	$(DC) exec reverb php artisan reverb:restart || true

octane-reload:
	$(DC) exec app php artisan octane:reload || true

clear:
	$(DC) exec app php artisan optimize:clear

storage-link:
	$(DC) exec app php artisan storage:link

cors-check:
	$(DC) exec app php artisan cors:check --fix

fix-permissions:
	@echo "Corrigiendo permisos con UID: $(USER_ID), GID: $(GROUP_ID)"
	sudo chown -R $(USER_ID):$(GROUP_ID) .
	find . -type d -exec chmod 775 {} + 2>/dev/null || true
	find . -type f -exec chmod 664 {} + 2>/dev/null || true
	find . -name "*.sh" -exec chmod +x {} + 2>/dev/null || true
	chmod +x artisan 2>/dev/null || true

help:
	@echo "APYGG - Makefile Commands"
	@echo "========================"
	@echo ""
	@echo "Uso: make [target] [ENV=dev|prod|staging] [SEARCH=true]"
	@echo "  ENV=dev   → activa mailpit"
	@echo "  ENV=prod  → activa pgbouncer"
	@echo "  SEARCH=true → activa meilisearch"
	@echo ""
	@echo "Ejemplos:"
	@echo "  make up                      # Dev con mailpit"
	@echo "  make up ENV=prod             # Prod con pgbouncer"
	@echo "  make up SEARCH=true          # Dev con meilisearch"
	@echo "  make up ENV=prod SEARCH=true # Prod con ambos"
	@echo ""
	@printf "  %-20s %s\n" "build" "Construir imágenes"
	@printf "  %-20s %s\n" "up" "Iniciar contenedores"
	@printf "  %-20s %s\n" "down" "Detener contenedores"
	@printf "  %-20s %s\n" "restart" "Reiniciar servicios"
	@printf "  %-20s %s\n" "redeploy" "Recrear contenedor app"
	@printf "  %-20s %s\n" "logs" "Ver logs"
	@printf "  %-20s %s\n" "ps" "Listar contenedores"
	@printf "  %-20s %s\n" "sh" "Shell en app"
	@printf "  %-20s %s\n" "exec cmd=..." "Ejecutar comando en app"
	@printf "  %-20s %s\n" "composer cmd=..." "Composer"
	@printf "  %-20s %s\n" "art cmd=..." "Artisan"
	@printf "  %-20s %s\n" "test" "Tests"
	@printf "  %-20s %s\n" "migrate" "Migrar BD"
	@printf "  %-20s %s\n" "seed" "Seed BD"
	@printf "  %-20s %s\n" "pint" "Formatear código"
	@printf "  %-20s %s\n" "phpstan" "Análisis estático"

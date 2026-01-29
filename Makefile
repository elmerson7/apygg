ENV ?= dev
DC := docker compose --project-name apygg --profile $(ENV)

# Detectar UID/GID del usuario actual del host para permisos correctos
USER_ID ?= $(shell id -u)
GROUP_ID ?= $(shell id -g)

export USER_ID
export GROUP_ID

# Comando por defecto: mostrar help
.DEFAULT_GOAL := help

.PHONY: build up down restart logs ps sh composer art key migrate seed jwt meilisearch-key scout horizon reverb tinker fix-permissions ensure-env clear test test-watch test-parallel test-coverage pint pint-test phpstan help

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
	@export $$(grep -v '^#' env/$(ENV).env 2>/dev/null | grep -v '^$$' | xargs) && \
	APP_ENV=$(ENV) $(DC) up -d

down:
	APP_ENV=$(ENV) $(DC) down

restart:
	@export $$(grep -v '^#' env/$(ENV).env 2>/dev/null | grep -v '^$$' | xargs) && \
	if [ -z "$(service)" ]; then \
		echo "Reiniciando todos los servicios..."; \
		APP_ENV=$(ENV) $(DC) restart; \
	else \
		echo "Reiniciando servicio $(service)..."; \
		APP_ENV=$(ENV) $(DC) restart $(service); \
	fi

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

test:
	$(DC) exec app composer test

test-watch:
	$(DC) exec app composer test:watch

test-parallel:
	$(DC) exec app composer test:parallel

test-coverage:
	$(DC) exec app php vendor/bin/pest --coverage

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

meilisearch-key:
	@echo "Generando clave segura para Meilisearch..."
	@KEY=$$(openssl rand -base64 32 | tr -d "=+/" | cut -c1-32); \
	echo "Clave generada: $$KEY"; \
	echo ""; \
	echo "Agrega esta línea en env/$(ENV).env:"; \
	echo "MEILISEARCH_KEY=$$KEY"; \
	echo ""; \
	echo "Luego reinicia Meilisearch: make restart service=meilisearch"

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

clear:
	$(DC) exec app php artisan optimize:clear

storage-link:
	$(DC) exec app php artisan storage:link

# Formatear código con Laravel Pint
pint:
	$(DC) exec app ./vendor/bin/pint

# Ver qué se formatearía sin aplicar cambios
pint-test:
	$(DC) exec app ./vendor/bin/pint --test

# Análisis estático con Larastan (PHPStan para Laravel)
phpstan:
	$(DC) exec app ./vendor/bin/phpstan analyse

# Ver tamaño de la base de datos (o tabla específica con table=nombre_tabla)
db-size:
	@if [ -z "$(table)" ]; then \
		$(DC) exec app php artisan db:size; \
	else \
		$(DC) exec app php artisan db:size --table=$(table); \
	fi

# Corregir permisos de archivos creados por Docker
fix-permissions:
	@echo "Corrigiendo permisos con UID: $(USER_ID), GID: $(GROUP_ID)"
	sudo chown -R $(USER_ID):$(GROUP_ID) .
	find . -type d -exec chmod 775 {} + 2>/dev/null || true
	find . -type f -exec chmod 664 {} + 2>/dev/null || true
	find . -name "*.sh" -exec chmod +x {} + 2>/dev/null || true
	chmod +x artisan 2>/dev/null || true
	@echo "Permisos corregidos correctamente"

# Instalar Git Hooks (pre-commit, commit-msg)
install-hooks:
	@chmod +x scripts/install-git-hooks.sh
	@./scripts/install-git-hooks.sh

# Helper para hacer commit con formato Conventional Commits
commit:
	@if [ -z "$(msg)" ]; then \
		echo "Uso: make commit msg=\"mensaje de commit\""; \
		echo "Ejemplo: make commit msg=\"Add git hooks\""; \
		exit 1; \
	fi
	@./scripts/commit-with-suggestion.sh "$(msg)"

# Mostrar ayuda con todos los comandos disponibles
help:
	@echo "APYGG - Makefile Commands"
	@echo "========================"
	@echo ""
	@echo "Uso: make [target] [ENV=dev|staging|prod]"
	@echo "Ejemplo: make up ENV=dev"
	@echo ""
	@echo "DOCKER/INFRAESTRUCTURA:"
	@printf "  %-20s %s\n" "build" "Construir imágenes Docker"
	@printf "  %-20s %s\n" "up" "Iniciar contenedores"
	@printf "  %-20s %s\n" "down" "Detener contenedores"
	@printf "  %-20s %s\n" "restart [service]" "Reiniciar servicios (o servicio específico)"
	@printf "  %-20s %s\n" "logs" "Ver logs de contenedores"
	@printf "  %-20s %s\n" "ps" "Listar contenedores en ejecución"
	@printf "  %-20s %s\n" "sh" "Abrir shell bash en contenedor app"
	@echo ""
	@echo "DESARROLLO:"
	@printf "  %-20s %s\n" "composer cmd=..." "Ejecutar comando composer"
	@printf "  %-20s %s\n" "art cmd=..." "Ejecutar comando artisan"
	@printf "  %-20s %s\n" "clear" "Limpiar cache y optimizaciones"
	@printf "  %-20s %s\n" "storage-link" "Crear enlace simbólico de storage"
	@echo ""
	@echo "TESTING:"
	@printf "  %-20s %s\n" "test" "Ejecutar tests"
	@printf "  %-20s %s\n" "test-watch" "Ejecutar tests en modo watch"
	@printf "  %-20s %s\n" "test-parallel" "Ejecutar tests en paralelo"
	@printf "  %-20s %s\n" "test-coverage" "Ejecutar tests con cobertura"
	@echo ""
	@echo "CODE QUALITY:"
	@printf "  %-20s %s\n" "pint" "Formatear código con Laravel Pint"
	@printf "  %-20s %s\n" "pint-test" "Ver qué se formatearía sin aplicar cambios"
	@printf "  %-20s %s\n" "phpstan" "Análisis estático con PHPStan"
	@echo ""
	@echo "BASE DE DATOS:"
	@printf "  %-20s %s\n" "migrate" "Ejecutar migraciones"
	@printf "  %-20s %s\n" "seed" "Ejecutar seeders"
	@printf "  %-20s %s\n" "db-size [table]" "Ver tamaño de BD o tabla específica"
	@echo ""
	@echo "CONFIGURACIÓN:"
	@printf "  %-20s %s\n" "key" "Generar APP_KEY"
	@printf "  %-20s %s\n" "jwt" "Generar clave JWT"
	@printf "  %-20s %s\n" "meilisearch-key" "Generar clave para Meilisearch"
	@printf "  %-20s %s\n" "ensure-env" "Crear archivo .env si no existe"
	@echo ""
	@echo "BÚSQUEDA (SCOUT):"
	@printf "  %-20s %s\n" "scout" "Sincronizar configuración de índices"
	@printf "  %-20s %s\n" "scout-sync" "Sincronizar configuración"
	@printf "  %-20s %s\n" "scout-import" "Importar todos los modelos"
	@printf "  %-20s %s\n" "scout-flush" "Limpiar todos los índices"
	@printf "  %-20s %s\n" "scout-reset" "Resetear todos los índices"
	@echo ""
	@echo "COLAS/BROADCASTING:"
	@printf "  %-20s %s\n" "horizon" "Reiniciar Laravel Horizon"
	@printf "  %-20s %s\n" "reverb" "Reiniciar Laravel Reverb"
	@echo ""
	@echo "UTILIDADES:"
	@printf "  %-20s %s\n" "cors-check" "Verificar configuración CORS"
	@printf "  %-20s %s\n" "fix-permissions" "Corregir permisos de archivos"
	@printf "  %-20s %s\n" "install-hooks" "Instalar Git hooks"
	@printf "  %-20s %s\n" "commit msg=\"...\"" "Hacer commit con formato Conventional Commits"
	@echo ""
	@echo "Para más información sobre un comando específico, consulta el Makefile."

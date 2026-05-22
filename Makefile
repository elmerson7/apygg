# ═══════════════════════════════════════════════════════════════════════
# APYGG - Makefile
# ═══════════════════════════════════════════════════════════════════════

# Entorno por defecto: dev
ENV ?= dev

# UID/GID del usuario host (para permisos de archivos en bind mount)
USER_ID ?= $(shell id -u)
GROUP_ID ?= $(shell id -g)

# Leer PROJECT desde compose.env (si existe) para --project-name
# Esto asegura que las imágenes y volúmenes usen el nombre correcto
PROJECT_NAME := $(shell if [ -f compose.env ]; then grep '^PROJECT=' compose.env | cut -d= -f2; else echo "apygg"; fi)

# Perfiles de Docker Compose por entorno:
# - prod: activa pgbouncer (connection pooling para alta carga)
# - search: activa meilisearch (motor de búsqueda full-text)
PROFILES  = $(if $(filter prod,$(ENV)),--profile prod)
PROFILES += $(if $(SEARCH),--profile search)

# Docker Compose usa:
# --project-name → nombre del proyecto para imágenes y volúmenes
# --env-file compose.env → variables de infraestructura (DB, Redis, etc.)
# .env se mapea directamente al contenedor Laravel via volumen
DC := docker compose $(PROFILES) --project-name $(PROJECT_NAME) --env-file compose.env

export USER_ID
export GROUP_ID

.DEFAULT_GOAL := help

.PHONY: validate build up upsearch down stop restart redeploy logs ps sh exec composer art key migrate seed schema jwt meilisearch-key scout flint test test-filter test-watch test-parallel test-coverage pint pint-test phpstan horizon reverb octane clear storage-link cors-check fix-permissions dbtest redistest meilitest help

# ═══════════════════════════════════════════════════════════════════════
# VALIDACIÓN
# ═══════════════════════════════════════════════════════════════════════

# Validar configuración antes de hacer build/up
# Verifica que compose.env tenga los valores necesarios
validate:
	@echo "═══════════════════════════════════════════════════════════════"
	@echo "Validando configuración..."
	@echo ""
	@if [ ! -f compose.env ]; then \
		echo "❌ ERROR: compose.env no existe"; \
		echo "   Ejecuta: cp compose.env.example compose.env"; \
		exit 1; \
	fi
	@echo "✅ compose.env existe"
	@echo ""
	@echo "Configuración actual:"
	@echo "  PROJECT:          $$(grep '^PROJECT=' compose.env | cut -d= -f2)"
	@echo "  APP_PORT:         $$(grep '^APP_PORT=' compose.env | cut -d= -f2)"
	@echo "  POSTGRES_PORT:    $$(grep '^POSTGRES_PORT=' compose.env | cut -d= -f2)"
	@echo "  REDIS_PORT:       $$(grep '^REDIS_PORT=' compose.env | cut -d= -f2)"
	@echo "  REVERB_PORT:      $$(grep '^REVERB_PORT=' compose.env | cut -d= -f2)"
	@echo "  MEILISEARCH_PORT: $$(grep '^MEILISEARCH_PORT=' compose.env | cut -d= -f2)"
	@echo "  PGBOUNCER_PORT:   $$(grep '^PGBOUNCER_PORT=' compose.env | cut -d= -f2)"
	@echo ""
	@if [ "$$(grep '^PROJECT=' compose.env | cut -d= -f2)" = "$$(grep '^PROJECT=' compose.env.example | cut -d= -f2)" ]; then \
		echo ""; \
		echo "⚠️  IMPORTANTE: Los valores son los del template (default)."; \
		echo "   Si clonas este proyecto, cambia PROJECT y puertos"; \
		echo "   en compose.env para evitar conflictos con otros proyectos."; \
	fi
	@echo ""
	@if [ ! -f .env ]; then \
		echo "⚠️  .env no existe - se creará automáticamente"; \
	else \
		echo "✅ .env existe"; \
	fi
	@echo ""
	@echo "═══════════════════════════════════════════════════════════════"

# ═══════════════════════════════════════════════════════════════════════
# DOCKER
# ═══════════════════════════════════════════════════════════════════════

# Construir imágenes Docker
# Args: USER_ID, GROUP_ID → se pasan al Dockerfile para permisos de archivos
build: validate
	@if [ ! -f .env ]; then \
		echo "Creando .env desde .env.example..."; \
		cp .env.example .env; \
	fi
	USER_ID=$(USER_ID) GROUP_ID=$(GROUP_ID) $(DC) build

# Iniciar contenedores
# Servicios siempre activos: app, postgres, redis, reverb, horizon, scheduler
# ENV=dev/staging: sin servicios extra (emails via Resend API)
# ENV=prod: + pgbouncer (connection pooling)
# SEARCH=true: + meilisearch
up: validate
	@if [ ! -f .env ]; then \
		echo "Creando .env desde .env.example..."; \
		cp .env.example .env; \
	fi
	$(DC) up -d

# Up con Meilisearch (busqueda)
upsearch: validate
	@if [ ! -f .env ]; then \
		echo "Creando .env desde .env.example..."; \
		cp .env.example .env; \
	fi
	@echo "Activando Meilisearch..."
	@if grep -q "^SCOUT_DRIVER=database" .env 2>/dev/null; then \
		sed -i 's/^SCOUT_DRIVER=database/SCOUT_DRIVER=meilisearch/' .env; \
		echo "  -> SCOUT_DRIVER=meilisearch"; \
	elif ! grep -q "^SCOUT_DRIVER=meilisearch" .env 2>/dev/null; then \
		sed -i 's/^SCOUT_DRIVER=.*/SCOUT_DRIVER=meilisearch/' .env; \
	fi
	@docker compose --project-name $(PROJECT_NAME) --env-file compose.env down
	@docker compose --profile search --project-name $(PROJECT_NAME) --env-file compose.env up -d

# Detener contenedores
down:
	$(DC) down

# Detener contenedores eliminando volúmenes
down-v:
	$(DC) down -v

# Detener sin eliminar volúmenes
stop:
	$(DC) stop

# Iniciar contenedores detenidos
start:
	$(DC) start

# Reiniciar servicios
restart:
	$(DC) restart $(service)

# Recrear solo app (para rebuilds rápidos sin perder datos)
redeploy:
	$(DC) up -d --force-recreate app

# Recrear contenedores recargando .env (sin perder datos)
reload:
	$(DC) down && $(DC) up -d

# Ver logs en tiempo real
logs:
	$(DC) logs -f --tail=200

# Listar contenedores
ps:
	$(DC) ps

# ═══════════════════════════════════════════════════════════════════════
# SHELL / COMANDOS
# ═══════════════════════════════════════════════════════════════════════

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

# Probar conexión a la base de datos
dbtest:
	@$(DC) exec app php artisan tinker --execute="try { DB::connection()->getPdo(); echo 'CONEXION_OK'; } catch (\Exception \$$e) { echo 'CONEXION_FALLO: ' . \$$e->getMessage(); }"

# Probar conexión a Redis
redistest:
	@$(DC) exec app php artisan tinker --execute="try { Cache::store('redis')->get('test'); echo 'REDIS_OK'; } catch (\Exception \$$e) { echo 'REDIS_FALLO: ' . \$$e->getMessage(); }"

# Probar conexión a Meilisearch
meilitest:
	@if docker ps --format "{{.Names}}" | grep -q "meili"; then \
		MEILI_URL="http://meilisearch:7700"; \
		RESULT=$$($(DC) exec app curl -s "$${MEILI_URL}/health" 2>/dev/null); \
		if echo "$$RESULT" | grep -q '"status":"available"'; then \
			echo "MEILISEARCH_OK"; \
		else \
			echo "MEILISEARCH_FALLO: No disponible"; \
		fi; \
	else \
		echo "MEILISEARCH_FALLO: Contenedor no encontrado"; \
	fi

# ═══════════════════════════════════════
# GENERACIÓN DE CLAVES
# ═══════════════════════════════════════════════════════════════════════

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

# ═══════════════════════════════════════════════════════════════════════
# BASE DE DATOS
# ═══════════════════════════════════════════════════════════════════════

# Ejecutar migraciones
migrate:
	$(DC) exec app php artisan migrate --force

# Ejecutar seeders
seed:
	$(DC) exec app php artisan db:seed --force

# Exportar esquema BD
schema:
	$(DC) exec app php artisan db:schema-dump

# ═══════════════════════════════════════════════════════════════════════
# TESTS
# ═══════════════════════════════════════════════════════════════════════

# Ejecutar tests
test:
	$(DC) exec app composer test

# Tests con filtro
test-filter:
	$(DC) exec app ./vendor/bin/pest --filter='$(filter)'

# Tests en modo watch
test-watch:
	$(DC) exec app composer test:watch

# Tests en paralelo
test-parallel:
	$(DC) exec app composer test:parallel

# Tests con coverage
test-coverage:
	$(DC) exec app ./vendor/bin/pest --coverage

# ═══════════════════════════════════════════════════════════════════════
# CODE QUALITY
# ═══════════════════════════════════════════════════════════════════════

# Formatear código con Laravel Pint
pint:
	$(DC) exec app ./vendor/bin/pint

# Ver qué se formatearía sin aplicar
pint-test:
	$(DC) exec app ./vendor/bin/pint --test

# Análisis estático PHPStan
phpstan:
	$(DC) exec app ./vendor/bin/phpstan analyse

# ═══════════════════════════════════════════════════════════════════════
# LARAVEL SERVICES
# ═══════════════════════════════════════════════════════════════════════

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

# ═══════════════════════════════════════════════════════════════════════
# UTILIDADES
# ═══════════════════════════════════════════════════════════════════════

# Corregir permisos de archivos creados por Docker
fix-permissions:
	@echo "Corrigiendo permisos con UID: $(USER_ID), GID: $(GROUP_ID)"
	sudo chown -R $(USER_ID):$(GROUP_ID) .
	find . -type d -exec chmod 775 {} + 2>/dev/null || true
	find . -type f -exec chmod 664 {} + 2>/dev/null || true
	find . -name "*.sh" -exec chmod +x {} + 2>/dev/null || true
	chmod +x artisan 2>/dev/null || true

# ═══════════════════════════════════════════════════════════════════════
# AYUDA
# ═══════════════════════════════════════════════════════════════════════

help:
	@echo "APYGG - Makefile Commands"
	@echo "═══════════════════════════════════════════════════════════════"
	@echo ""
	@echo "Uso: make [target] [ENV=dev|staging|prod] [SEARCH=true]"
	@echo ""
	@echo "Entornos:"
	@echo "  ENV=dev      → desarrollo (sin servicios extra)"
	@echo "  ENV=staging  → staging (sin servicios extra)"
	@echo "  ENV=prod     → producción (incluye pgbouncer)"
	@echo ""
	@echo "Servicios opcionales:"
	@echo "  SEARCH=true  → incluir meilisearch"
	@echo ""
	@echo "Archivos de configuración:"
	@echo "  compose.env  → Docker (puertos, PROJECT)"
	@echo "  .env         → Laravel (APP_ENV, DB_HOST, etc.)"
	@echo ""
	@echo "Validación (siempre ejecutar antes del primer build):"
	@printf "  %-20s %s\n" "validate" "Verificar configuración"
	@echo ""
	@echo "Ejemplos:"
	@echo "  make validate         # Verificar configuración"
	@echo "  make build            # Build (valida primero)"
	@echo "  make up               # Dev local"
	@echo "  make up ENV=staging   # Staging"
	@echo "  make up ENV=prod      # Prod (con pgbouncer)"
	@echo "  make up SEARCH=true   # Dev con meilisearch"
	@echo "  make up ENV=prod SEARCH=true  # Prod + meilisearch"
	@echo ""
	@echo "Docker:"
	@printf "  %-20s %s\n" "build" "Construir imágenes"
	@printf "  %-20s %s\n" "up" "Iniciar contenedores"
	@printf "  %-20s %s\n" "down" "Detener contenedores"
	@printf "  %-20s %s\n" "restart" "Reiniciar servicios"
	@printf "  %-20s %s\n" "redeploy" "Recrear app"
	@printf "  %-20s %s\n" "logs" "Ver logs"
	@printf "  %-20s %s\n" "ps" "Listar contenedores"
	@echo ""
	@echo "Shell:"
	@printf "  %-20s %s\n" "sh" "Shell en app"
	@printf "  %-20s %s\n" "exec" "Ejecutar comando"
	@printf "  %-20s %s\n" "composer" "Composer"
	@printf "  %-20s %s\n" "art" "Artisan"
	@echo ""
	@echo "Database:"
	@printf "  %-20s %s\n" "migrate" "Migrar BD"
	@printf "  %-20s %s\n" "seed" "Seed BD"
	@printf "  %-20s %s\n" "schema" "Exportar esquema"
	@echo ""
	@echo "Tests:"
	@printf "  %-20s %s\n" "test" "Ejecutar tests"
	@printf "  %-20s %s\n" "test-watch" "Tests en watch"
	@printf "  %-20s %s\n" "test-coverage" "Tests con coverage"
	@echo ""
	@echo "Code Quality:"
	@printf "  %-20s %s\n" "pint" "Formatear código"
	@printf "  %-20s %s\n" "phpstan" "Análisis estático"
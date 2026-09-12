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

.PHONY: validate build up upsearch down stop restart redeploy logs ps sh exec composer art key migrate seed schema jwt meilisearch-key scout flint test test-filter test-watch test-parallel test-coverage test-coverage-min test-arch test-mutate test-unit test-feature testdb testdb-migrate testdb-parallel pint pint-test phpstan check install-hooks horizon reverb octane clear storage-link cors-check fix-permissions dbtest redistest meilitest check-project backup backup-list schema-dump restore-backup restore-remote prune-images prune-images-dry-run prune-builder help

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
build: validate check-project
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
up: validate check-project
	@if [ ! -f .env ]; then \
		echo "Creando .env desde .env.example..."; \
		cp .env.example .env; \
	fi
	$(DC) up -d

# Up con Meilisearch (busqueda)
upsearch: validate check-project
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

# Verificar si PROJECT es el boilerplate (apygg)
# Si es así, pregunta confirmación antes de build/up
check-project:
	@project=$$(grep '^PROJECT=' compose.env | cut -d= -f2); \
	if [ "$$project" = "apygg" ]; then \
		echo ""; \
		echo "⚠️  PROJECT=apygg (nombre del boilerplate)"; \
		echo ""; \
		read -p "¿Estás seguro de continuar? (s/N): " confirm; \
		if [ "$$confirm" != "s" ] && [ "$$confirm" != "S" ]; then \
			echo "❌ Cancelado."; \
			exit 1; \
		fi; \
	fi

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

# Sincronizar settings e importar datos a Meilisearch
scout:
	$(DC) exec app php artisan scout:sync-index-settings
	$(DC) exec app php artisan scout:import "App\Models\User"

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

# Tests en paralelo. Requiere una BD por proceso: make testdb-parallel
test-parallel:
	$(DC) exec app ./vendor/bin/pest --parallel --processes=$(or $(PROCESSES),4)

# Tests con coverage (pcov se enciende solo para esta ejecución)
test-coverage:
	$(DC) exec app php -d pcov.enabled=1 ./vendor/bin/pest --coverage

# Tests con coverage y umbral mínimo. Uso: make test-coverage-min MIN=70
test-coverage-min:
	$(DC) exec app php -d pcov.enabled=1 ./vendor/bin/pest --coverage --min=$(or $(MIN),70)

# Tests de arquitectura (reglas estructurales del código)
test-arch:
	$(DC) exec app ./vendor/bin/pest --group=arch

# Mutation testing: cambia el código a propósito y comprueba que algún test falle
test-mutate:
	$(DC) exec app php -d pcov.enabled=1 ./vendor/bin/pest --mutate --covered-only

# Solo tests unitarios
test-unit:
	$(DC) exec app ./vendor/bin/pest tests/Unit

# Solo tests de integración
test-feature:
	$(DC) exec app ./vendor/bin/pest tests/Feature

# Crear la BD de testing aislada (<DB_DATABASE>_test). No toca la BD de desarrollo.
testdb:
	@bash ./create_testdb.sh

# Crear la BD de testing + migrar desde cero y sembrar datos base
testdb-migrate:
	@bash ./create_testdb.sh --migrate

# Preparar las BD para tests en paralelo. Uso: make testdb-parallel PROCESSES=4
testdb-parallel:
	@bash ./create_testdb.sh --migrate --parallel=$(or $(PROCESSES),4)

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

# Quality gate completo: formatea y analiza. Ejecútalo tras cualquier cambio PHP.
check: pint phpstan

# Instalar los git hooks del repo (pre-commit + commit-msg)
install-hooks:
	@bash scripts/install-git-hooks.sh

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
# BACKUPS
# ═══════════════════════════════════════════════════════════════════════

# Crear un backup de la base de datos (y subirlo al disco remoto si está activo)
backup:
	$(DC) exec app php artisan backup:create

# Listar backups disponibles (local y remoto)
backup-list:
	$(DC) exec app php artisan backup:list --remote

# Exportar solo la estructura (DDL) de la BD a database/schema.sql
schema-dump:
	$(DC) exec app php artisan db:schema-dump

# Restaurar un backup LOCAL. Uso: make restore-backup file=storage/app/backups/backup_x.sql.gz
restore-backup:
	@if [ -z "$(file)" ]; then \
		echo "Especifica el archivo: make restore-backup file=storage/app/backups/backup.sql.gz"; \
		exit 1; \
	fi
	@if [ ! -f "$(file)" ]; then \
		echo "Archivo no encontrado: $(file)"; \
		exit 1; \
	fi
	@echo "⚠️  Esto SOBRESCRIBE la base de datos actual."
	@read -p "¿Continuar? (s/N): " confirm; \
	if [ "$$confirm" != "s" ] && [ "$$confirm" != "S" ]; then echo "Cancelado."; exit 1; fi
	$(DC) exec app php artisan backup:restore "$(file)" --force

# Descargar el último backup remoto (S3/R2) y restaurarlo. Uso opcional: file=backup_x.sql.gz
restore-remote:
	$(DC) exec app php artisan backup:restore-remote $(if $(file),--file=$(file)) --force

# ═══════════════════════════════════════════════════════════════════════
# MANTENIMIENTO DOCKER
# ═══════════════════════════════════════════════════════════════════════

# Ventana de retención por defecto para el pruning de imágenes
UNTIL ?= 72h

# Borrar imágenes antiguas del proyecto (protege las en uso y las 2 más recientes)
prune-images:
	@if [ "$(DRY_RUN)" = "1" ]; then \
		bash scripts/prune-images.sh --dry-run --until $(UNTIL); \
	else \
		echo "═══════════════════════════════════════════════════════════════"; \
		echo " prune-images — LIMPIEZA REAL (UNTIL=$(UNTIL))"; \
		echo " Protege imágenes en uso + conserva las 2 más recientes"; \
		echo "═══════════════════════════════════════════════════════════════"; \
		docker system df 2>/dev/null || true; \
		echo ""; \
		bash scripts/prune-images.sh --dry-run --until $(UNTIL) 2>&1 | grep -E "CANDIDATA|protegida|Total candidatas" || true; \
		echo ""; \
		read -p "¿Continuar con el borrado? (s/N): " confirm; \
		if [ "$$confirm" != "s" ] && [ "$$confirm" != "S" ]; then echo "❌ Cancelado."; exit 1; fi; \
		bash scripts/prune-images.sh --until $(UNTIL); \
	fi

# Simular el pruning sin borrar nada
prune-images-dry-run:
	@bash scripts/prune-images.sh --dry-run --until $(UNTIL)

# Limpiar la caché del builder de Docker (nunca toca volúmenes)
prune-builder:
	@echo "Limpiando builder cache anterior a $(UNTIL)..."
	@docker builder prune -f --filter "until=$(UNTIL)" 2>&1 || true
	@docker builder du 2>&1 | head -n 20 || true

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
	@printf "  %-20s %s\n" "scout" "Sincronizar settings e importar datos a Meilisearch"
	@printf "  %-20s %s\n" "seed" "Seed BD"
	@printf "  %-20s %s\n" "schema" "Exportar esquema"
	@echo ""
	@echo "Tests:"
	@printf "  %-20s %s\n" "testdb" "Crear BD de testing aislada (<db>_test)"
	@printf "  %-20s %s\n" "testdb-migrate" "Crear BD de testing + migrar y sembrar"
	@printf "  %-20s %s\n" "testdb-parallel" "Una BD por proceso (PROCESSES=4)"
	@printf "  %-20s %s\n" "test" "Ejecutar toda la suite"
	@printf "  %-20s %s\n" "test-unit" "Solo tests unitarios"
	@printf "  %-20s %s\n" "test-feature" "Solo tests de integración"
	@printf "  %-20s %s\n" "test-arch" "Solo reglas de arquitectura"
	@printf "  %-20s %s\n" "test-parallel" "Tests en paralelo (PROCESSES=4)"
	@printf "  %-20s %s\n" "test-watch" "Tests en watch"
	@printf "  %-20s %s\n" "test-coverage" "Informe de cobertura"
	@printf "  %-20s %s\n" "test-coverage-min" "Cobertura con umbral (MIN=70)"
	@printf "  %-20s %s\n" "test-mutate" "Mutation testing (lento)"
	@echo ""
	@echo "Code Quality:"
	@printf "  %-20s %s\n" "check" "pint + phpstan (obligatorio tras cambios PHP)"
	@printf "  %-20s %s\n" "pint" "Formatear código"
	@printf "  %-20s %s\n" "phpstan" "Análisis estático"
	@printf "  %-20s %s\n" "install-hooks" "Instalar git hooks del repo"
	@echo ""
	@echo "Backups:"
	@printf "  %-20s %s\n" "backup" "Crear backup de la BD"
	@printf "  %-20s %s\n" "backup-list" "Listar backups (local + remoto)"
	@printf "  %-20s %s\n" "schema-dump" "Exportar solo el DDL a database/schema.sql"
	@printf "  %-20s %s\n" "restore-backup" "Restaurar backup local (file=...)"
	@printf "  %-20s %s\n" "restore-remote" "Descargar y restaurar backup remoto"
	@echo ""
	@echo "Mantenimiento Docker:"
	@printf "  %-20s %s\n" "prune-images" "Borrar imágenes antiguas (UNTIL=72h)"
	@printf "  %-20s %s\n" "prune-images-dry-run" "Simular el borrado de imágenes"
	@printf "  %-20s %s\n" "prune-builder" "Limpiar caché del builder"
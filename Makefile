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
# - dev: activa mailpit + emulador S3 (make up-dev; ver docs/local-services.md)
PROFILES  = $(if $(filter prod,$(ENV)),--profile prod)
PROFILES += $(if $(SEARCH),--profile search)
PROFILES += $(if $(DEV),--profile dev)

# Docker Compose usa:
# --project-name → nombre del proyecto para imágenes y volúmenes
# --env-file compose.env → variables de infraestructura (DB, Redis, etc.)
# .env se mapea directamente al contenedor Laravel via volumen
DC := docker compose $(PROFILES) --project-name $(PROJECT_NAME) --env-file compose.env

export USER_ID
export GROUP_ID

.DEFAULT_GOAL := help

.PHONY: validate build up upsearch down down-v stop start restart redeploy reload logs ps sh exec composer art key jwt meilisearch-key migrate seed schema scout test test-filter test-watch test-parallel test-coverage test-coverage-min test-arch test-mutate test-unit test-feature testdb testdb-migrate testdb-parallel dbtest redistest meilitest pint pint-test phpstan check install-hooks horizon reverb octane clear storage-link cors-check fix-permissions check-project backup backup-list schema-dump restore-backup restore-remote prune-images prune-images-dry-run prune-builder up-dev mail-ui mail-clear s3-ui s3-init s3-test test-integration help

# Targets por tema: cada archivo de make/ agrupa uno
# El orden de include es fijo (no comodín) para que el resultado sea estable
include make/docker.mk
include make/app.mk
include make/db.mk
include make/test.mk
include make/quality.mk
include make/backup.mk
include make/dev.mk
include make/help.mk

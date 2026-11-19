# Docker: validación, build, arranque/parada, logs y mantenimiento de imágenes (targets: make/*.mk; ver Makefile raíz)
# Validar configuración antes de hacer build/up
# Verifica que compose.env tenga los valores necesarios
validate:
	@bash scripts/validate-env.sh

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
	$(DCALL) down

# Detener contenedores eliminando volúmenes
down-v:
	$(DCALL) down -v

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


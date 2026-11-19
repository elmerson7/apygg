# Proyecto nuevo: init, setup, doctor, fresh, upgrade-check y entorno por worktree (targets: make/*.mk)
# Guía de uso: docs/new-project.md

# Arranca un proyecto nuevo desde APYGG: genera compose.env y .env, renombra el boilerplate.
#   make init NAME=miapp [PORT_BASE=8100] [TITLE="Mi App"] [VENDOR=miorg] [README=1] [YES=1]
init:
	@bash scripts/init-project.sh --name "$(NAME)" \
		$(if $(PORT_BASE),--port-base "$(PORT_BASE)") \
		$(if $(TITLE),--title "$(TITLE)") \
		$(if $(VENDOR),--vendor "$(VENDOR)") \
		$(if $(README),--readme) \
		$(if $(YES),--yes)

# Entorno aislado por worktree (para desarrollar el boilerplate con varios agentes):
# compose.env y .env con PROJECT=apygg-<NAME>, puertos desde 8200 y BD propia. Ver docs/new-project.md
wt-env:
	@bash scripts/init-project.sh --worktree --name "$(NAME)" $(if $(PORT_BASE),--port-base "$(PORT_BASE)") $(if $(YES),--yes)

# Dev por defecto en local (perfil dev: Mailpit + S3). `make setup DEV=false` lo desactiva.
setup: DEV := $(if $(DEV),$(DEV),true)

# Del clon a la API corriendo: build + up + composer + migraciones + seed + BD de tests + S3 local
setup:
	@test -f compose.env && test -f .env || { echo "❌ Faltan compose.env y/o .env. Ejecuta primero: make init NAME=<slug>"; exit 1; }
	@$(MAKE) --no-print-directory validate
	@$(MAKE) --no-print-directory build DEV=$(DEV)
	@echo "→ Arrancando PostgreSQL, Redis y la app..."
	$(DC) up -d postgres redis app
	@echo "→ Esperando a PostgreSQL y Redis..."
	@bash scripts/wait-healthy.sh $(PROJECT_NAME)-postgres $(PROJECT_NAME)-redis
	@echo "→ Esperando a que la app instale las dependencias (primera vez: 1-3 min)..."
	@for i in $$(seq 1 240); do \
		if $(DC) logs app 2>&1 | grep -q "Starting Laravel Octane"; then break; fi; \
		if [ $$i -eq 240 ]; then echo "❌ La app no arrancó a tiempo. Mira: make logs"; exit 1; fi; \
		sleep 3; \
	done
	$(DC) exec -T -u appuser app composer install --no-interaction
	@$(DC) exec -T -u appuser app composer validate --no-check-all --no-check-publish --quiet >/dev/null 2>&1 \
		|| { echo "→ Actualizando el hash de composer.lock (el nombre del paquete cambió)..."; \
		     $(DC) exec -T -u appuser app composer update --lock --no-interaction --no-scripts; }
	@echo "→ Levantando el resto de servicios..."
	$(DC) up -d
	@echo "→ Migraciones y datos base..."
	$(DC) exec -T -u appuser app php artisan migrate --force
	$(DC) exec -T -u appuser app php artisan db:seed --force
	@echo "→ BD de tests..."
	@bash ./create_testdb.sh --migrate
	$(DC) exec -T -u appuser app php artisan storage:link --force
	@if [ "$(filter true 1 yes,$(DEV))" != "" ]; then \
		echo "→ Buckets S3 locales..."; \
		$(MAKE) --no-print-directory s3-init DEV=true; \
	fi
	@bash scripts/wait-healthy.sh $(PROJECT_NAME)-app
	@bash scripts/setup-summary.sh "$(DEV)"

# Migra volúmenes con nombre antiguo (<proyecto>_pgdata) a <proyecto>-pgdata. Stack parado. DRY=1 solo simula.
rename-volumes:
	@bash scripts/migrate-docker-names.sh $(if $(DRY),--dry-run) $(PROJECT_NAME)

# Diagnóstico del entorno local: explica cómo arreglar lo que falle (código de salida != 0 si algo falla)
doctor:
	@bash scripts/doctor.sh

# Rehacer la BD de desarrollo desde cero (migrate:fresh --seed). Pide confirmación; no en producción.
fresh:
	@appenv=$$(grep -m1 '^APP_ENV=' .env 2>/dev/null | cut -d= -f2); \
	case "$(ENV)$$appenv" in *prod*) echo "❌ make fresh borra la BD: no se ejecuta con ENV=prod ni APP_ENV=production."; exit 1;; esac; \
	db=$$(grep -m1 '^DB_DATABASE=' .env | cut -d= -f2); \
	echo "⚠️  Esto BORRA todas las tablas de la BD '$$db' y vuelve a migrar y sembrar."; \
	if [ "$(YES)" != "1" ]; then \
		read -p "¿Continuar? (s/N): " confirm; \
		if [ "$$confirm" != "s" ] && [ "$$confirm" != "S" ]; then echo "❌ Cancelado."; exit 1; fi; \
	fi
	$(DC) exec -T -u appuser app php artisan migrate:fresh --seed --force

# Qué trae el boilerplate (remoto upstream) que este proyecto aún no tiene
upgrade-check:
	@bash scripts/upgrade-check.sh

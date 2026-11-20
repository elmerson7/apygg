# Despliegue de producción por SSH + Docker Compose (targets: make/*.mk; ver Makefile raíz). Guía: docs/deploy.md
# Variables: .env.deploy (no se versiona; plantilla en .env.deploy.example) o línea de make:
#   make deploy DEPLOY_HOST=deploy@vps DEPLOY_PATH=/srv/miapp IMAGE=ghcr.io/org/repo IMAGE_TAG=sha-abc1234 SMOKE_URL=https://api.x.com
IMAGE_LOCAL ?= apygg-prod-test
GIT_SHA     ?= $(shell git rev-parse HEAD 2>/dev/null || echo unknown)
GIT_SHORT   ?= $(shell git rev-parse --short=7 HEAD 2>/dev/null || echo unknown)

# Construye la imagen de PRODUCCIÓN en local (misma que publica release.yml). Etiqueta: <IMAGE_LOCAL>:sha-<commit>
# Usa el candado de verificación si existe VERIFY_LOCK (máquinas con poca RAM).
image:
	$(if $(VERIFY_LOCK),flock $(VERIFY_LOCK)) docker build -f docker/app/Dockerfile.prod \
		--build-arg GIT_SHA=$(GIT_SHA) --build-arg APP_RELEASE=sha-$(GIT_SHORT) \
		-t $(IMAGE_LOCAL):sha-$(GIT_SHORT) .

# Despliega IMAGE:IMAGE_TAG: pull, migraciones en un contenedor aparte ANTES del cambio, up --wait y humo.
# Si el arranque o el humo fallan y DEPLOY_AUTO_ROLLBACK != false, vuelve a la etiqueta anterior.
deploy:
	@bash scripts/deploy/deploy.sh deploy

# Vuelve a la etiqueta anterior guardada en el servidor (o a ROLLBACK_TAG=<etiqueta>). No revierte migraciones.
rollback:
	@bash scripts/deploy/deploy.sh rollback

# Humo contra SMOKE_URL: /health/live, /health/ready, release (EXPECTED_RELEASE=sha-abc1234 opcional) y login opcional
# (SMOKE_EMAIL + SMOKE_PASSWORD). No necesita SSH.
smoke:
	@bash scripts/deploy/deploy.sh smoke

# Etiqueta actual y anterior en el servidor
deploy-status:
	@bash scripts/deploy/deploy.sh status

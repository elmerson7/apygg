# Cliente de pruebas (módulo opcional «frontend-demo»): Vite + React + TypeScript con tipos generados desde
# docs/openapi.json. Todo corre en Node EFÍMERO de Docker (nunca Node en el host), como `make types`.
# Detalle: docs/frontend-demo.md
FRONTEND_NODE_IMAGE ?= node:22-bookworm-slim
# Dónde está la API para `make frontend-dev` (el navegador habla con `/api`; Vite lo reenvía aquí).
VITE_API_URL ?= http://localhost:8000
FRONTEND_DEV_PORT ?= 5173

FRONTEND_RUN = docker run --rm -u $$(id -u):$$(id -g) -e HOME=/tmp -v "$(CURDIR)":/work -w /work/frontend-demo $(FRONTEND_NODE_IMAGE)

.PHONY: frontend-install frontend-types frontend-dev frontend-build frontend-test frontend-e2e

# Dependencias exactas del package-lock (npm ci) en frontend-demo/node_modules (fuera de git)
frontend-install:
	$(FRONTEND_RUN) npm ci --no-audit --no-fund

# Regenera src/api/schema.d.ts desde docs/openapi.json (misma versión de openapi-typescript que `make types`)
frontend-types:
	@test -d frontend-demo/node_modules/.bin || $(MAKE) frontend-install
	$(FRONTEND_RUN) npm run api:types

# Servidor de desarrollo con recarga en caliente: http://localhost:$(FRONTEND_DEV_PORT) (API en VITE_API_URL)
frontend-dev: frontend-types
	docker run --rm -it --network host -u $$(id -u):$$(id -g) -e HOME=/tmp -e VITE_API_URL=$(VITE_API_URL) \
		-v "$(CURDIR)":/work -w /work/frontend-demo $(FRONTEND_NODE_IMAGE) npm run dev -- --port $(FRONTEND_DEV_PORT) --strictPort

# Typecheck (app y Playwright) + build de producción en frontend-demo/dist
frontend-build: frontend-types
	$(FRONTEND_RUN) npm run build

# Typecheck + tests unitarios del cliente (Vitest)
frontend-test: frontend-types
	$(FRONTEND_RUN) sh -c 'npm run typecheck && npm test'

# Recorridos Playwright (escritorio y móvil) contra un stack TEMPORAL propio; lo baja al terminar (~2,5 GB)
frontend-e2e:
	@bash scripts/frontend-e2e.sh run $(ARGS)

# Contrato OpenAPI y tipos para los clientes (targets: make/*.mk). Guía: docs/api-contract.md
# Versión fija de openapi-typescript: el .d.ts generado cambia entre versiones, así que se sube a propósito.
OPENAPI_TS_VERSION ?= 7.10.1

# Exporta el documento OpenAPI 3.1 (Scramble) a docs/openapi.json. Ejecútalo tras cambiar rutas/requests/resources.
openapi:
	$(DC) exec app php artisan openapi:export

# Falla si docs/openapi.json está desactualizado (lo mismo que comprueba el test)
openapi-check:
	$(DC) exec app php artisan openapi:export --check

# Tipos TypeScript para los fronts desde docs/openapi.json -> docs/api-types.d.ts (sin tocar el stack: Node efímero)
types:
	docker run --rm -u $$(id -u):$$(id -g) -e HOME=/tmp -v "$(CURDIR)":/work -w /work node:22-alpine \
		npx --yes openapi-typescript@$(OPENAPI_TS_VERSION) docs/openapi.json -o docs/api-types.d.ts

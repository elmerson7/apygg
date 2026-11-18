# Base de datos y conexiones de prueba: migraciones, seeds, esquema, Meilisearch (targets: make/*.mk)
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

# Exportar solo la estructura (DDL) de la BD a database/schema.sql
schema-dump:
	$(DC) exec app php artisan db:schema-dump


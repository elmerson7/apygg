# Tests (Pest) y bases de datos de testing (targets: make/*.mk)
# Suites (phpunit.xml): Unit, Feature, Arch (por defecto) + Contract, Concurrency, E2E, Integration (a propósito).
# Cobertura mínima de la suite por defecto; sube junto con la cobertura real (misma cifra en .github/workflows/ci.yml).
COVERAGE_MIN ?= 55

# Suite por defecto: Unit + Feature + Arch
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

# Tests con coverage y umbral mínimo. Uso: make test-coverage-min [MIN=NN] (por defecto COVERAGE_MIN)
test-coverage-min:
	$(DC) exec app php -d pcov.enabled=1 ./vendor/bin/pest --coverage --min=$(or $(MIN),$(COVERAGE_MIN))

# Tests de arquitectura (reglas estructurales del código)
test-arch:
	$(DC) exec app ./vendor/bin/pest --testsuite=Arch

# Todas las suites que no necesitan servicios extra (Unit, Feature, Arch, Contract, Concurrency)
test-all:
	$(DC) exec app ./vendor/bin/pest --testsuite=Unit,Feature,Arch,Contract,Concurrency

# Contrato público de la API (forma de las respuestas)
test-contract:
	$(DC) exec app ./vendor/bin/pest --testsuite=Contract

# Carreras reales con procesos PHP hijos (datos comprometidos en la BD de tests)
test-concurrency:
	$(DC) exec app ./vendor/bin/pest --testsuite=Concurrency

# Recorrido E2E por HTTP real contra el stack levantado de ESTE proyecto (make setup).
# Corre dentro del contenedor app: la API es http://localhost:8000 (el APP_URL del .env es la
# vista del host; úsalo como E2E_BASE_URL si lanzas pest desde fuera). Mailpit se usa si
# MAIL_HOST=mailpit y S3 si FILE_DISK_PRIVATE=s3_private; si no, esos pasos se omiten.
# Ver docs/testing.md ("Pruebas E2E").
test-e2e:
	$(DC) exec -T app sh -c '\
		export E2E_BASE_URL=$${E2E_BASE_URL:-http://localhost:8000} E2E_APP_TITLE="$$APP_TITLE"; \
		if [ "$$MAIL_HOST" = mailpit ]; then export E2E_MAIL_URL=$${E2E_MAIL_URL:-http://mailpit:8025}; fi; \
		if [ "$$FILE_DISK_PRIVATE" = s3_private ]; then \
			export E2E_S3_ENDPOINT=$${E2E_S3_ENDPOINT:-$$AWS_ENDPOINT} E2E_S3_KEY=$$AWS_ACCESS_KEY_ID \
				E2E_S3_SECRET=$$AWS_SECRET_ACCESS_KEY E2E_S3_PRIVATE_BUCKET=$${AWS_PRIVATE_BUCKET:-$$AWS_BUCKET}; \
		fi; \
		exec ./vendor/bin/pest --testsuite=E2E'

# Solo los tests con cambios sin commitear según Git (Pest --dirty): ciclo rápido al desarrollar
test-changed:
	$(DC) exec app ./vendor/bin/pest --dirty

# Lo mismo que el CI: calidad (pint + phpstan) + suite por defecto con cobertura mínima
ci: check test-coverage-min

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


# Pruebas de integración contra los servicios reales del perfil dev (Mailpit y S3).
# Requiere make up-dev y MAIL_MAILER / AWS_* de .env apuntando a ellos; sin los
# servicios, la suite se omite. Ver docs/local-services.md
test-integration:
	$(DC) exec app sh -c 'INTEGRATION_MAIL_URL=http://mailpit:8025 INTEGRATION_S3_ENDPOINT=$$AWS_ENDPOINT ./vendor/bin/pest --testsuite=Integration'

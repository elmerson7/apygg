# Tests (Pest) y bases de datos de testing (targets: make/*.mk)
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


# Pruebas de integración contra los servicios reales del perfil dev (Mailpit y S3).
# Requiere make up-dev y MAIL_MAILER / AWS_* de .env apuntando a ellos; sin los
# servicios, la suite se omite. Ver docs/local-services.md
test-integration:
	$(DC) exec app sh -c 'INTEGRATION_MAIL_URL=http://mailpit:8025 INTEGRATION_S3_ENDPOINT=$$AWS_ENDPOINT ./vendor/bin/pest --testsuite=Integration'

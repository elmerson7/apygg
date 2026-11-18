# Calidad de código: Pint, PHPStan, quality gate y git hooks (targets: make/*.mk)
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


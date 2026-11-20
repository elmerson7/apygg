# Módulos opcionales: listar, quitar y crear (docs/modules.md). Todo corre dentro del contenedor.

# Tabla de módulos con estado (presente/quitado), tipo y dependencias
module-list:
	$(DC) exec app php artisan module:list

# Quita un módulo opcional: make module-remove name=billing [DRY=1] [NO_VERIFY=1]
module-remove:
	@test -n "$(name)" || { echo "Uso: make module-remove name=<modulo> [DRY=1]"; exit 1; }
	$(DC) exec app php artisan module:remove $(name) $(if $(DRY),--dry-run) $(if $(NO_VERIFY),--no-verify)

# Esqueleto de un módulo nuevo: make module-new name=mi-modulo [DRY=1]
module-new:
	@test -n "$(name)" || { echo "Uso: make module-new name=<kebab-case> [DRY=1]"; exit 1; }
	$(DC) exec app php artisan module:new $(name) $(if $(DRY),--dry-run)

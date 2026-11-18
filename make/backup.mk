# Backups y restauración de la base de datos (targets: make/*.mk)
# Crear un backup de la base de datos (y subirlo al disco remoto si está activo)
backup:
	$(DC) exec app php artisan backup:create

# Listar backups disponibles (local y remoto)
backup-list:
	$(DC) exec app php artisan backup:list --remote

# Restaurar un backup LOCAL. Uso: make restore-backup file=storage/app/backups/backup_x.sql.gz
restore-backup:
	@if [ -z "$(file)" ]; then \
		echo "Especifica el archivo: make restore-backup file=storage/app/backups/backup.sql.gz"; \
		exit 1; \
	fi
	@if [ ! -f "$(file)" ]; then \
		echo "Archivo no encontrado: $(file)"; \
		exit 1; \
	fi
	@echo "⚠️  Esto SOBRESCRIBE la base de datos actual."
	@read -p "¿Continuar? (s/N): " confirm; \
	if [ "$$confirm" != "s" ] && [ "$$confirm" != "S" ]; then echo "Cancelado."; exit 1; fi
	$(DC) exec app php artisan backup:restore "$(file)" --force

# Descargar el último backup remoto (S3/R2) y restaurarlo. Uso opcional: file=backup_x.sql.gz
restore-remote:
	$(DC) exec app php artisan backup:restore-remote $(if $(file),--file=$(file)) --force


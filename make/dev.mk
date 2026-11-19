# Servicios de desarrollo: Mailpit y emulador S3 (perfil dev de Compose; ver docs/local-services.md)

# Puertos del host, leídos de compose.env (con el mismo valor por defecto que docker-compose.yml)
MAILPIT_UI_PORT_V  = $(or $(shell grep '^MAILPIT_UI_PORT=' compose.env 2>/dev/null | cut -d= -f2),8017)
S3_CONSOLE_PORT_V  = $(or $(shell grep '^S3_CONSOLE_PORT=' compose.env 2>/dev/null | cut -d= -f2),8019)

# Igual que DC pero siempre con el perfil dev (para tareas sueltas como s3-init)
DCDEV := docker compose $(PROFILES) --profile dev --project-name $(PROJECT_NAME) --env-file compose.env

# Stack + Mailpit + emulador S3 (crea los buckets al arrancar)
up-dev:
	@$(MAKE) --no-print-directory up DEV=true
	@echo ""
	@echo "Bandeja de correo: http://localhost:$(MAILPIT_UI_PORT_V)   (make mail-ui)"
	@echo "Consola S3:        http://localhost:$(S3_CONSOLE_PORT_V)   (make s3-ui)"
	@echo "Para usarlos desde la app: ver docs/local-services.md (MAIL_MAILER=smtp, FILE_DISK_*=s3)"

# Mostrar la URL de la bandeja de Mailpit
mail-ui:
	@echo "http://localhost:$(MAILPIT_UI_PORT_V)"

# Vaciar la bandeja de Mailpit (API)
mail-clear:
	@curl -fsS -X DELETE "http://127.0.0.1:$(MAILPIT_UI_PORT_V)/api/v1/messages" && echo "Bandeja vaciada"

# Mostrar la URL de la consola del emulador S3 (usuario/clave: S3_ACCESS_KEY / S3_SECRET_KEY de compose.env)
s3-ui:
	@echo "http://localhost:$(S3_CONSOLE_PORT_V)"

# Crear (o confirmar) los buckets público y privado. Idempotente.
s3-init:
	$(DCDEV) run --rm s3-init

# Probar el almacenamiento S3: sube, lee (URL firmada en el privado) y borra un archivo
s3-test:
	$(DC) exec app php artisan storage:check --disk=s3 --disk=s3_private

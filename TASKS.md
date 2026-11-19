# TASKS — Estado real del boilerplate APYGG

Revisado contra el código el 2026-11-19. Lo marcado como hecho está comprobado en el código, las rutas o los tests.

## Hecho

### Módulos

- **Auth JWT**: login, registro, refresh, recuperación y reset de contraseña, logout, `me` y cambio de contraseña (`routes/api/auth.php`). Versión de token por usuario (`token_version`), refresh que no sirve como access, bloqueo por IP e identidad.
- **API Keys**: alta, listado, revocación y rotación; guard `api-key`; sin scopes no hay acceso.
- **RBAC con spatie/laravel-permission 8.5**: guard `api`, rutas con `permission:x.y,api`, super admin por `Gate::after`, `PermissionCatalogTest`, teams preparado y apagado.
- **Usuarios y perfil**: CRUD, restauración, roles por usuario con jerarquía, perfil y preferencias en `/user`.
- **Webhooks salientes**: CRUD, entregas, reintentos, firma `timestamp.cuerpo`, protección SSRF (solo https, sin redes privadas).
- **Webhook de correo entrante**: `/webhooks/mail` con firma Svix.
- **Archivos**: tipo por contenido, discos público y privado, URLs firmadas (`docs/files.md`).
- **Correo**: un solo camino (Mailable/Notification en cola) con cuentas en BD, plantillas, pausa, límites y registro (`/admin/mail`).
- **Gateway LLM** (`/admin/llm`), **asistente RAG** (`/docs/*`), **menú dinámico** (`/admin/menu`), **settings**.
- **Seguridad**: auditoría con escaneos, lista de bloqueo, headers, rate limiting adaptativo, registro de eventos. Un solo registro de seguridad por login/logout (`LogAuthenticationEvents` sobre los eventos de Laravel).
- **Back-office Filament 5 (opcional)**: `/backoffice`, MFA TOTP obligatorio (`docs/backoffice.md`).
- **Logging, health, caché, backups de BD, búsqueda (Scout + Meilisearch), Reverb, Horizon, Scramble** (`/docs/api`).
- **Infraestructura**: Docker Compose (`app`, `reverb`, `horizon`, `scheduler`, `postgres`, `redis`; `pgbouncer` y `meilisearch` opcionales), CI (lint, tests, `composer audit`), Makefile en `make/*.mk`.

### Arquitectura y calidad

- Servicios inyectados por interfaz, sin repositorios ni DTOs, sin estado estático mutable (Octane); `ArchTest` lo vigila.
- Una sola base de controller; Form Requests tipados; respuestas con `sendSuccess`/`sendError`/`sendPaginated`.
- Sin saneado global de entrada.
- Columnas JSON en `jsonb` (migración `2026_11_19_000040`, `JsonbColumnsTest`).
- Código muerto retirado en la ola de limpieza (2026-11-19): DTOs, Enums, Events/Broadcasting, Helpers sin uso, Rules sin uso, tres Jobs, `FcmService`, `QrCodeService`, `DeviceTokenController`, login social con su paquete (Socialite) y `Exceptions/Handler`.
- Documentación puesta al día contra el código y `docs/upgrading.md` con los pasos para proyectos derivados.
- Tests Pest (Unit, Feature, Arch) en verde; `pint` y `phpstan` limpios.

## Pendiente

### Importante

- [ ] **Multiempresa.** Existen `Tenant`, `create_tenants_table`, `BelongsToTenant`, `TenantScope`, el middleware `ResolveTenant` (sin registrar) y `PERMISSION_TEAMS=false`. Ninguna tabla tiene `tenant_id` y `config/multi-tenancy.php` viene apagado. Decidir si se completa (fase de multiempresa) o se retira todo junto. `Tenant::$currentTenant` es la única excepción a la regla de estáticos.
- [ ] **Suscripciones y cobros.** No hay pasarela de pagos, ni Cashier ni modelos de suscripción. Decisión de cada proyecto; no entra en el boilerplate salvo que se decida lo contrario.
- [x] **Mailpit y S3 local en Compose.** Perfil `dev` (`make up-dev`): Mailpit y RustFS (MinIO ya no publica imágenes), buckets automáticos, `make s3-test`, suite `Integration`. Ver `docs/local-services.md`.
- [ ] **`make init`.** Un único comando que cree `compose.env` y `.env`, levante los servicios, genere `APP_KEY` y el secreto JWT, migre y siembre. Hoy son varios pasos manuales (ver README, "Instalación").
- [ ] **Backup de archivos.** `BackupCreateCommand` lo marca como TODO.
- [ ] **Mover archivos privados antiguos** al disco privado en proyectos que vengan de una versión previa (no hay comando; ver `docs/upgrading.md`).
- [ ] **Cobertura.** El objetivo del 80 % en código crítico no está medido (`make test-coverage`).

### Menor

- [ ] **Feature flags sin uso.** `config/features.php` define `advanced_search`, `export_users` y `two_factor_auth`, pero ninguna ruta usa el middleware `feature:`.
- [ ] **Dependencias sin uso.** `setasign/fpdf`, `setasign/fpdi`, `barryvdh/laravel-snappy` y `spatie/laravel-query-builder` no aparecen en `app/`. Decidir si se quitan. `chillerlan/php-qrcode` se queda: lo exige Filament para el QR del MFA.
- [ ] **Tabla `device_tokens`** sin modelo ni rutas (se dejó para no borrar datos de proyectos derivados). Retirarla con una migración si no se va a usar push.
- [ ] **Failed login.** `LogAuthenticationEvents::handleFailed` existe, pero la API no dispara el evento `Failed` de Laravel (los fallos se registran con `LogService` y el bloqueo); valorar dispararlo desde `AuthService`.

# TASKS — Estado real del boilerplate APYGG

Revisado contra el código el 2026-11-27. Lo marcado como hecho está comprobado en el código, las rutas o los tests.
El detalle de cada pieza está en su documento de `docs/` (índice en `AGENTS.md`, sección «Normas»).

## Hecho

### Módulos

- **Auth JWT**: login, registro, refresh rotado con detección de reutilización, recuperación y reset de contraseña, logout, `me`, cambio de contraseña, sesiones por dispositivo, 2FA TOTP y verificación de correo (`docs/sessions-and-2fa.md`). JWT asimétrico opcional (`docs/jwt-keys.md`).
- **Login social** (Google, GitHub) y **login por OTP**: módulos opcionales (`docs/social-login.md`, `docs/otp-login.md`).
- **API Keys**: alta, listado, revocación y rotación; guard `api-key`; sin scopes no hay acceso; crear y rotar piden `api-keys.manage` y una key nunca da más que su dueño (`docs/api-keys.md`).
- **RBAC con spatie/laravel-permission**: guard `api`, rutas con `permission:x.y,api`, super admin por `Gate::after`, `PermissionCatalogTest`, teams preparado y apagado. Nadie edita ni toma la cuenta de alguien con más permisos.
- **Multiempresa** (opt-in): `TenantContext`, `BelongsToTenant` fail-closed, middleware `tenant`, alta de empresa, invitaciones y miembros con jerarquía (`docs/multi-tenancy.md`, `docs/tenant-onboarding.md`).
- **Planes, suscripciones y cobros**: máquina de estados, facturas numeradas, `billing:renew` idempotente, Stripe, conciliación (`docs/billing.md`). Límites por plan y feature flags en BD (`docs/settings-and-flags.md`).
- **Usuarios y perfil**, **impersonación de soporte**, **auditoría por empresa**, **privacidad (RGPD/LGPD)**, **notificaciones** (correo, bandeja, push, SMS), **i18n**, **dinero** y **numeraciones atómicas**.
- **Webhooks** salientes y entrantes, **integraciones** con modo real/simulado, **idempotencia** (`Idempotency-Key`), **sincronización offline** (opcional).
- **Archivos**: discos público y privado, URL firmada, subida directa presignada, cuotas por plan, escaneo antivirus opcional (ClamAV por perfil, VirusTotal por hash) y reprocesado de imágenes (`docs/files.md`).
- **Exportar/importar** CSV y XLSX en streaming y PDF (`docs/export-import.md`).
- **Correo**: un solo camino (Mailable/Notification en cola) con cuentas en BD, plantillas, pausa, límites y registro.
- **Gateway LLM**, **asistente RAG**, **menú dinámico**, **ajustes**, **modo mantenimiento**, **métricas Prometheus** (opcional).
- **Seguridad**: lista de bloqueo, headers, rate limiting, CORS por lista, redacción única de datos personales y secretos en logs y Sentry, validación de arranque en producción.
- **Back-office Filament (opcional)**: `/backoffice`, MFA TOTP obligatorio (`docs/backoffice.md`).
- **Infraestructura**: Docker Compose con perfiles (`dev`, `search`, `clamav`, `prod`), `make init NAME=<slug>` para proyectos nuevos, despliegue con imagen inmutable y rollback (`docs/deploy.md`), módulos quitables (`make module-remove`).

### Arquitectura y calidad

- Servicios inyectados por interfaz, sin repositorios, sin estado estático mutable (Octane); `ArchTest` lo vigila.
- Una sola base de controller; Form Requests tipados; respuestas con `sendSuccess`/`sendError`/`sendPaginated`; contrato OpenAPI versionado.
- PHPStan nivel 8 sin baseline; Pint; Pest (Unit, Feature, Arch, Contract, Concurrency, E2E, Integration); pruebas de mutación en dinero, secuencias, tokens, sesiones, aislamiento por empresa e idempotencia.
- Cobertura medida en CI con mínimo del 80 % (`COVERAGE_MIN` en `make/test.mk`); el E2E bloquea los PR.

## Pendiente

- [ ] **Backup de archivos.** `backup:create --files` falla a propósito: los archivos no se respaldan con ese comando (`docs/backups-system.md`). Falta un respaldo de los discos (o documentar el del proveedor de S3).
- [ ] **Mover archivos privados antiguos** al disco privado en proyectos que vengan de una versión previa (no hay comando; ver `docs/upgrading.md`).
- [ ] **Middleware `feature:` sin uso en rutas.** Los flags se gestionan en `/admin/feature-flags`, pero ninguna ruta del boilerplate usa `feature:`; queda como ejemplo para los proyectos.
- [ ] **Evento `Failed` de login.** `LogAuthenticationEvents::handleFailed` existe, pero la API no dispara el evento `Failed` de Laravel (los fallos se registran con `LogService` y el bloqueo); valorar dispararlo desde `AuthService`.
- [ ] **Subir el mínimo de cobertura** cuando la medida lo permita (hoy 80 %).

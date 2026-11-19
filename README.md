# APYGG — Boilerplate de API

Boilerplate de API con Laravel 13 y PHP 8.5, servido con FrankenPHP (Octane) y pensado como base para proyectos nuevos. El código es infraestructura reutilizable: la lógica de un negocio concreto no va aquí.

Para agentes y LLM, la fuente de verdad es [AGENTS.md](./AGENTS.md). Para el detalle técnico, ver [ARCHITECTURE.md](./ARCHITECTURE.md) y [TECH_STACK.md](./TECH_STACK.md).

## Stack

Versiones tomadas de `composer.lock` y de los Dockerfile de `docker/`:

| Componente | Versión |
|---|---|
| PHP | 8.5 (imagen `dunglas/frankenphp:1.11-php8.5-bookworm`) |
| Laravel | 13.31.0 |
| Octane | 2.19.1 |
| PostgreSQL | 18 (`postgres:18-alpine`) |
| Redis | 7.4 (`redis:7.4-alpine`) |
| Laravel Horizon | 5.49.0 |
| Laravel Reverb | 1.11.1 |
| Laravel Scout + Meilisearch | Scout 10.25.0, cliente 1.17.0, servidor 1.16 |
| JWT (php-open-source-saver) | 2.9.3 |
| Scramble (OpenAPI) | 0.13.43 |
| Sentry | 4.27.0 |
| Pest | 4.7.8 |

## Módulos

Cada módulo está implementado en el código. Lo que aún no está completo figura en [TASKS.md](./TASKS.md).

- **Autenticación**: JWT (login, registro, refresh, recuperación y cambio de contraseña, logout) y API Keys con scopes (`auth:api-key`, `api-key-scope:`).
- **Autorización**: RBAC con spatie/laravel-permission (modelos `Role` y `Permission`, middleware `permission:` y `role:`; ver `docs/permissions.md`).
- **Usuarios y perfil**: CRUD de usuarios, restauración, roles por usuario, perfil y preferencias en `/user`.
- **Logging**: `LogService` y canales `activity`, `security`, `auth` y `sentry`; registro de API en BD.
- **Seguridad**: `SecurityAuditService` (escaneos en `/admin/security/scans`), lista de bloqueo (`/admin/blacklist`), headers de seguridad, rate limiting adaptativo y registro de eventos.
- **Webhooks salientes**: suscripciones, entregas, reintentos y rotación de secreto (`/webhooks`).
- **Correo**: multi-cuenta y multi-proveedor (Mailables/Notifications estándar enganchados al sistema de correo; modelos en `app/Models/Mail`), registro de envíos y webhook de entrega verificado con firma Svix (`/admin/mail`).
- **Gateway LLM**: capa agnóstica del proveedor (`app/Services/LLM`) con áreas, modelos de respaldo y auditoría (`/admin/llm`).
- **Asistente de documentación (RAG)**: indexa los Markdown del proyecto y responde preguntas (`/docs/search`, `/docs/ask`).
- **Menú dinámico**: ítems y visibilidad desde BD (`/admin/menu`).
- **Archivos**: `FileService` con discos público y privado (`local`, `public`, `s3`, `s3_private`); ver `docs/files.md`.
- **Búsqueda**: Scout con Meilisearch (`/search`).
- **Colas y procesos**: Redis, Horizon y scheduler (servicios de Docker Compose).
- **WebSockets**: Reverb y autorización de canales (`/broadcasting/auth`).
- **Caché**: `CacheService` y comandos `cache:*`.
- **Backups**: volcado de BD con subida opcional a remoto (comandos `backup:*`). El respaldo de archivos no está implementado.
- **Feature flags**: `config/features.php` y helper `Feature`.
- **Health**: `/health`, `/health/live`, `/health/ready` y `/health/detailed` (autenticado).
- **Documentación de API**: Scramble en `/docs/api`.

Autenticación social (Socialite) tiene controlador y servicio, pero no tiene rutas. Ver TASKS.md.

## Requisitos

- Docker y Docker Compose v2 (`docker compose`).
- Make (opcional, recomendado).

## Instalación

```bash
cp compose.env.example compose.env   # variables de Docker (puertos, PROJECT)
make build                           # valida compose.env, crea .env si falta y construye las imágenes
make up                              # levanta los servicios base (app, postgres, redis, reverb, horizon, scheduler)
make key                             # muestra una APP_KEY nueva (ver nota)
make jwt                             # muestra un secreto JWT nuevo (ver nota)
make migrate
make seed
```

`make key` y `make jwt` solo muestran el valor (`--show`); copia el resultado a `APP_KEY` y al secreto JWT de `.env`.

Antes de correr los tests por primera vez: `make testdb-migrate`.

## Comandos Make

Los targets viven en `make/*.mk` (uno por tema). El `Makefile` raíz define variables e incluye esos archivos. `make help` lista todos.

| Tema | Comandos |
|---|---|
| Servicios | `make build`, `make up`, `make down`, `make restart service=app`, `make logs`, `make ps`, `make sh` |
| Entornos | `make up ENV=prod` (añade pgbouncer), `make up SEARCH=true` (añade meilisearch) |
| App | `make art cmd="<artisan>"`, `make composer cmd="<composer>"`, `make key`, `make jwt`, `make clear`, `make storage-link`, `make horizon`, `make reverb`, `make octane` |
| Base de datos | `make migrate`, `make seed`, `make schema`, `make schema-dump`, `make dbtest`, `make redistest`, `make meilitest`, `make upsearch`, `make scout` |
| Backups | `make backup`, `make backup-list`, `make restore-backup`, `make restore-remote` |
| Tests | `make test`, `make test-unit`, `make test-feature`, `make test-arch`, `make test-filter filter=<nombre>`, `make test-coverage`, `make test-mutate`, `make testdb-migrate` |
| Calidad | `make pint`, `make phpstan`, `make check` (pint + phpstan), `make install-hooks` |

Los comandos `artisan` y `composer` siempre se ejecutan dentro del contenedor, nunca en el host.

## Puertos del host

Valores por defecto de `compose.env.example`:

| Servicio | Variable | Puerto |
|---|---|---|
| App (Octane) | `APP_PORT` | 8010 |
| PostgreSQL | `POSTGRES_PORT` | 8011 |
| Reverb (WebSockets) | `REVERB_PORT` | 8012 |
| Redis | `REDIS_PORT` | 8013 |
| Meilisearch | `MEILISEARCH_PORT` | 8014 |
| PgBouncer (perfil `prod`) | `PGBOUNCER_PORT` | 8015 |

`compose.env` controla los puertos del host. Los valores de `compose.env` se pasan a Compose con `--env-file`.

## Rutas

La API no usa el prefijo `/api`. Cada módulo vive en `routes/api/<modulo>.php` y se incluye desde `routes/api.php`.

| Ámbito | Prefijo | Ejemplos |
|---|---|---|
| Público | ninguno | `POST /auth/login`, `GET /health`, `GET /docs/api` |
| Recurso autenticado | el del módulo | `GET /users`, `POST /files`, `GET /search`, `GET /webhooks` |
| Perfil del usuario | `/user` | `GET /user/profile`, `PUT /user/preferences` |
| Administración | `/admin/...` | `GET /admin/logs/activity`, `GET /admin/mail/providers`, `GET /admin/llm/status` |

Middleware con alias registrados en `bootstrap/app.php`: `api-key-scope:`, `permission:`, `role:`, `role_or_permission:`, `ip.whitelist`, `feature:`, `auth.optional`. `auth:api` es el guard JWT y `auth:api-key` el guard de API Keys (ver `docs/api-keys.md`).

## Estructura

```
app/
├── Console/Commands/   # comandos artisan
├── Contracts/          # interfaces de servicios y repositorios
├── DTOs/               # objetos de transferencia
├── Enums/              # enumeraciones
├── Events/ Listeners/  # eventos y listeners
├── Exceptions/
├── Helpers/            # ApiResponse, Feature, DateHelper, etc.
├── Http/               # Controllers, Middleware, Requests, Resources
├── Jobs/
├── Logging/            # handlers y processors de Monolog
├── Mail/               # Mailables
├── Models/             # Eloquent (Logs/, Mail/, Docs/, Llm/)
├── Repositories/
├── Services/           # lógica de aplicación (Docs/, LLM/, Logging/, Mail/, Menu/, Tools/)
└── Traits/
config/                 # configuración por archivo
database/               # migraciones, factories, seeders
docker/                 # Dockerfile y configuración de cada servicio
make/                   # targets de Make por tema
routes/                 # api.php + api/*.php
tests/                  # Unit/ (incluye Arch) y Feature/
```

## Documentación

- [AGENTS.md](./AGENTS.md): reglas para agentes y LLM (fuente de verdad).
- [ARCHITECTURE.md](./ARCHITECTURE.md): capas, módulos y flujos.
- [TECH_STACK.md](./TECH_STACK.md): stack completo y versiones.
- [TASKS.md](./TASKS.md): estado actual y pendientes.
- [CONTRIBUTING.md](./CONTRIBUTING.md): cómo contribuir.
- [CHANGELOG.md](./CHANGELOG.md): cambios por versión.
- [docs/](./docs/): documentación por tema (caché, backups, correo, LLM, WebSockets, etc.).
- [docs/archive/PLAN_ACCION_2025.md](./docs/archive/PLAN_ACCION_2025.md): plan inicial, histórico.

## Licencia

MIT, según el campo `license` de `composer.json`. No hay archivo LICENSE en el repositorio.

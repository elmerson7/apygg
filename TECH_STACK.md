# Stack tecnológico — APYGG

Versiones de `composer.lock`, `package.json` y los Dockerfile de `docker/`. Si cambian las dependencias, actualiza este archivo a partir de esas fuentes.

## Runtime y framework

| Tecnología | Versión | Fuente |
|---|---|---|
| PHP | 8.5 | `composer.json` (`^8.5`), `docker/app/Dockerfile` |
| Laravel | 13.31.0 | `composer.lock` |
| Laravel Octane | 2.19.1 | `composer.lock` |
| FrankenPHP | 1.13.6 con PHP 8.5.13 (`dunglas/frankenphp:1.13.6-php8.5.13-bookworm`, etiqueta fijada) | `docker/app/Dockerfile` |

Extensiones PHP instaladas en la imagen: `pcntl`, `intl`, `zip`, `opcache`, `gd`, `pdo_pgsql`, `bcmath`, entre otras (`docker/app/Dockerfile`).

## Paquetes principales

| Paquete | Versión | Uso en el código |
|---|---|---|
| `php-open-source-saver/jwt-auth` | 2.9.3 | JWT (`AuthService`, `TokenService`) |
| `laravel/horizon` | 5.49.0 | Panel y gestión de colas |
| `laravel/reverb` | 1.11.1 | WebSockets (`BroadcastAuthController`, `routes/channels.php`) |
| `laravel/scout` | 10.25.0 | Búsqueda (driver `meilisearch` por defecto) |
| `meilisearch/meilisearch-php` | 1.17.0 | Cliente de Meilisearch |
| `league/flysystem-aws-s3-v3` | 3.35.3 | Disco `s3` de `config/filesystems.php` |
| `resend/resend-laravel` | 1.4.0 | Proveedor de correo (`config/mail.php`) |
| `dedoc/scramble` | 0.13.43 | Documentación OpenAPI en `/docs/api` |
| `sentry/sentry-laravel` | 4.27.0 | Captura de excepciones (`bootstrap/app.php`) |
| `laravel/telescope` | 5.24.0 | Depuración (provider registrado en `bootstrap/providers.php`) |
| `chillerlan/php-qrcode` | 5.0.5 | QR del MFA TOTP del back-office (Filament 5 exige `^5.0`) |
| `spatie/laravel-permission` | 8.5.0 | RBAC (roles, permisos, middlewares `permission:`/`role:`); guard `api`, teams apagado. Ver `docs/permissions.md` |
| `filament/filament` | 5.11.4 | Back-office interno opcional (`app/Filament`, panel `backoffice`); trae Livewire 4.4.8. Ver `docs/backoffice.md` |
| `openspout/openspout` | 4.33.0 | Lectura y escritura de CSV/XLSX en streaming (exportar/importar). Ver `docs/export-import.md` |
| `dompdf/dompdf` | 3.1.6 | Único motor de PDF (`PdfRendererInterface`), PHP puro sin binarios externos |

Dependencias del `composer.json` sin uso en `app/`, `config/`, `routes/` ni `bootstrap/` (comprobado con grep):


## Desarrollo y calidad

| Herramienta | Versión | Uso |
|---|---|---|
| Pest | 4.7.8 | Tests (`tests/Unit`, `tests/Feature`, arquitectura con `arch()`) |
| Laravel Pint | 1.32.1 | Formato PSR-12 (`make pint`) |
| PHPStan | 2.2.13 | Análisis estático, nivel 6, sin baseline ni excludePaths (`phpstan.neon`) |
| Larastan | 3.12.0 | Extensión de PHPStan para Laravel |
| Laravel Pail | 1.2.7 | Logs en vivo (script `composer dev`) |
| Mockery | 1.6.15 | Mocks en tests |

## Frontend (solo tooling)

`package.json` declara Vite (`^7.0.7`), Tailwind CSS (`^4.0.0`), `@tailwindcss/vite`, `laravel-vite-plugin` (`^2.0.0`) y axios. El repositorio no tiene lockfile de npm. La API no sirve vistas.

## Base de datos, caché y colas

| Servicio | Imagen | Notas |
|---|---|---|
| PostgreSQL | `postgres:18-alpine` (build propio) | Instala PostGIS y pgvector en `docker/postgres/Dockerfile`. El asistente RAG no usa pgvector (ver ARCHITECTURE.md). |
| Redis | `redis:7.4-alpine` (build propio) | Caché, sesiones y cola (`QUEUE_CONNECTION=redis` por defecto en `config/queue.php`) |
| PgBouncer | `pgbouncer/pgbouncer:latest` | Solo en el perfil `prod` (`docker-compose.yml`) |
| Meilisearch | `getmeili/meilisearch:v1.16` | Solo con `SEARCH=true` (perfil `search`) |

## Contenedores y orquestación

- Docker Compose v2 (`docker compose`). El `Makefile` lo llama con `--project-name` y `--env-file compose.env`.
- Servicios de `docker-compose.yml`: `app`, `reverb`, `horizon`, `scheduler`, `postgres`, `redis`, y los opcionales `pgbouncer` y `meilisearch`.

## Integración continua

Workflows en `.github/workflows/`:

- `ci.yml`: job `lint` (Pint en modo test y PHPStan), job `test` (migraciones y Pest con cobertura) y `composer audit`.
- `cd.yml`: despliegue.
- `sonarcloud.yml`: análisis de calidad.
- `.github/dependabot.yml`: actualización de dependencias.

## Requisitos

- Desarrollo: Docker Engine y Docker Compose v2. Make es opcional.
- Ningún requisito de CPU, RAM o disco está fijado en el repositorio.

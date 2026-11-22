# Despliegue a producción (imagen inmutable + SSH + Docker Compose)

Un solo camino, el real de los SaaS que parten de APYGG: **un VPS con Docker Compose**. Las plantillas antiguas
(cPanel, Railway, blue-green, canary, rolling) se archivaron sin probar en `docs/archive/despliegue-plantillas/`.

```
push a main / tag vX.Y.Z ──► release.yml: build + Trivy + push a GHCR (sha-<commit>, vX.Y.Z)
                                   │
        make deploy IMAGE_TAG=sha-abc1234 (o workflow CD manual)
                                   │  SSH
                                   ▼
   servidor: pull → migrate (contenedor aparte) → up --wait → humo → (rollback automático si falla)
```

## 1. La imagen

- `docker/app/Dockerfile.prod`: multi-stage (`base` → `vendor` → `final`). Sin dependencias de desarrollo
  (`composer install --no-dev`, autoloader autoritativo), sin git/composer/pcov, opcache sin revalidar
  (`docker/app/php.prod.ini`), usuario **no root** (`appuser`, UID 10001), `HEALTHCHECK` a `/health/live`.
  El `.dockerignore` propio (`Dockerfile.prod.dockerignore`) deja fuera `.env`, tests y `.git`.
- El entrypoint (`entrypoint-prod.sh`) ejecuta `php artisan optimize` con el entorno **real** del contenedor y luego el
  comando (Octane/FrankenPHP por defecto; Horizon, scheduler y migraciones lo sustituyen por `command:`).
- `APP_RELEASE` (= `sha-<7 hex>`) se graba en la imagen y lo expone `GET /health` y `GET /health/ready` como
  `release`. Es lo que comprueba el humo.
- **Etiquetas**: `sha-<commit>` en cada push a `main`/tag y `vX.Y.Z` en tags `v*`. **`latest` no existe**: `deploy.sh`
  rechaza cualquier etiqueta que no sea `sha-<hex>` o `vX.Y.Z`.
- Cache de capas (GitHub Actions cache), SBOM y provenance en el push, y escaneo **Trivy** (CRITICAL con arreglo
  disponible bloquea la publicación) antes de publicar.
- Local: `make image` → `apygg-prod-test:sha-<commit>` (con `VERIFY_LOCK=/ruta/lock` usa `flock` en máquinas justas de RAM).

## 2. Preparar el servidor (una vez)

```bash
# en el VPS: Docker + Compose instalados, usuario de despliegue en el grupo docker
sudo mkdir -p /srv/miapp && sudo chown deploy: /srv/miapp
echo "$GHCR_TOKEN" | docker login ghcr.io -u <usuario> --password-stdin   # token con read:packages
```

En `DEPLOY_PATH` (p. ej. `/srv/miapp`) crea **solo** el `.env` de Laravel de producción (`APP_KEY`, `JWT_SECRET`,
`DB_*`, `REDIS_*`, `APP_URL`...). `make deploy` copia `deploy/compose.prod.yml` y mantiene `deploy.env`
(`IMAGE`, `IMAGE_TAG`, `PREVIOUS_TAG`) y `deploy.history` (registro de cada despliegue/rollback).

- **PostgreSQL y Redis no van en el compose**: apunta `DB_HOST`/`REDIS_HOST` a tu instancia. Si corren en el propio
  VPS usa `host.docker.internal` (ya mapeado) y que escuchen en la interfaz del bridge de Docker.
- El `app` publica `127.0.0.1:${APP_PORT:-8000}`: pon delante tu proxy TLS (Caddy/nginx). Reverb es opcional:
  `COMPOSE_PROFILES_EXTRA=reverb`.
- **`.env` de producción**: parte de `.env.production.example` (`APP_DEBUG=false`, `LOG_LEVEL=warning`, Sentry a tasa baja,
  sin claves por defecto). Los logs salen en **JSON por stderr** (`LOG_STACK=stderr,sentry_logs`,
  `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`), una línea por evento con `trace_id`: `docker compose logs app`
  o tu agregador; el compose los rota (`LOG_MAX_SIZE`, `LOG_MAX_FILE`). No dupliques variables: lo que no está ahí se toma de `.env.example`.
- **Validación de arranque** (`App\Support\Security\ProductionConfigGuard`, desde `AppServiceProvider::boot`): con
  `APP_ENV=production` la app **se niega a arrancar** (excepción con la lista de problemas) si hay `APP_DEBUG=true`,
  `JWT_SECRET` vacío/corto/por defecto, `DB_PASSWORD`, `REDIS_PASSWORD` o `MEILISEARCH_KEY` por defecto (`secret`,
  `masterKey`, la de `compose.env.example`...) o Sentry con DSN y muestreo de trazas/perfiles a 1.0 (100 %). No actúa en
  comandos de construcción (`optimize`, `package:discover`...). Apagado de emergencia: `PRODUCTION_GUARD=false` (no lo dejes).
- Servicios: `app` (Octane), `horizon`, `scheduler`, `reverb` (perfil) y `migrate` (perfil `tools`, un solo uso).

## 3. Variables (`.env.deploy`, plantilla `.env.deploy.example`; no se versiona)

| Variable | Qué |
|---|---|
| `DEPLOY_HOST` | `user@host` (SSH por clave, sin contraseña) |
| `DEPLOY_PATH` | carpeta absoluta en el servidor con el `.env` de Laravel |
| `IMAGE` | `ghcr.io/<org>/<repo>` (minúsculas) |
| `IMAGE_TAG` | `sha-<commit>` o `vX.Y.Z` (también por línea de make) |
| `PROJECT` | nombre del stack Compose (por defecto `apygg`) |
| `SMOKE_URL` | URL pública para el humo; sin ella el despliegue omite el humo |
| `DEPLOY_AUTO_ROLLBACK` | `true` (defecto): vuelve atrás si el arranque o el humo fallan |
| `DEPLOY_PORT`, `DEPLOY_SSH_KEY`, `COMPOSE_PROFILES_EXTRA`, `SMOKE_EMAIL`, `SMOKE_PASSWORD` | opcionales |

## 4. Comandos

```bash
make deploy IMAGE_TAG=sha-abc1234     # pull → migrate → up --wait → humo → rollback auto
make rollback                          # a la etiqueta anterior (ROLLBACK_TAG=v1.2.0 para elegir otra)
make smoke SMOKE_URL=https://api.x.com [EXPECTED_RELEASE=sha-abc1234]
make deploy-status                     # etiqueta actual y anterior
```

Orden de `deploy` (`scripts/deploy/remote.sh`):

1. `docker compose pull` de la etiqueta nueva (no toca nada en ejecución).
2. **Migraciones** (`migrate --force`) en un contenedor de una sola vez con la imagen nueva, **antes** de cambiar
   servicios. Si fallan (o el pull), el script sale sin cambiar nada y **no** hace rollback (no hay nada que revertir).
3. Guarda `PREVIOUS_TAG` = la etiqueta en ejecución y fija la nueva en `deploy.env`.
4. `up -d --wait`: recrea `app`, `horizon` y `scheduler` y espera a que estén sanos. Horizon recibe SIGTERM y termina
   los jobs en curso (hasta 120 s); `app` se sustituye con healthcheck a `/health/ready`.
5. Humo desde la máquina que lanza el despliegue. Si falla: rollback (si `DEPLOY_AUTO_ROLLBACK=true`) y salida 1.

**Límite honesto**: con un solo contenedor `app` hay un corte de pocos segundos al recrearlo. Si necesitas cero
cortes, ejecuta 2 réplicas detrás de tu proxy (quita `container_name`/`ports` del servicio) y recréalas de una en una;
no viene resuelto en el boilerplate.

### Rollback y migraciones

El rollback cambia **solo la imagen**; las migraciones no se revierten. Por eso las migraciones deben ser compatibles
con la versión anterior (expandir/contraer: `docs/migrations.md`). Tras un rollback, `PREVIOUS_TAG` pasa a ser la
etiqueta que falló; repetir `make rollback` alterna entre las dos.

## 5. Humo (`scripts/deploy/smoke.sh`)

Con reintentos (`SMOKE_RETRIES`=10, `SMOKE_DELAY`=3 s): `/health/live`, `/health/ready` (DB y Redis), `release`
informada (si se despliega por `sha-…` debe coincidir con la etiqueta) y, si defines `SMOKE_EMAIL`/`SMOKE_PASSWORD`,
`POST /auth/login` debe dar 2xx. Usa un usuario **dedicado y sin permisos** para el login; el límite de intentos del
login aplica.

## 6. GitHub Actions

- `release.yml`: build, Trivy, humo de la imagen y push (no necesita secretos: usa `GITHUB_TOKEN`).
- `cd.yml` (manual): elige entorno (Environments `staging`/`production`), `image_tag` o `rollback`. Secrets del
  Environment: `DEPLOY_HOST`, `DEPLOY_SSH_KEY`, `DEPLOY_PATH`, `SMOKE_URL`; opcionales `DEPLOY_PORT`,
  `DEPLOY_KNOWN_HOSTS` (recomendado: sin él se acepta la huella del primer contacto), `SMOKE_EMAIL`, `SMOKE_PASSWORD`;
  variable `PROJECT`. Usa los mismos scripts que `make`.

## 7. Qué está probado y qué no

Probado en el repo: sintaxis y `shellcheck` de los scripts, `actionlint` de los workflows, `docker compose config`,
simulación de `deploy`/`rollback`/humo con un `docker` falso (migración que falla, arranque que falla, release
distinta, etiqueta `latest`) y la construcción de la imagen. **No** se ha desplegado contra un servidor real ni
publicado en GHCR: haz el primer despliegue en `staging`.

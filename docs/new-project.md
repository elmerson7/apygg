# Proyecto nuevo desde APYGG

De un clon vacío a una API corriendo en dos comandos, sin colisionar con otros
proyectos Docker de la misma máquina.

```bash
git clone <url-de-apygg> miapp && cd miapp
make init NAME=miapp        # genera compose.env y .env, renombra el boilerplate
make setup                  # build + up + composer + migraciones + seed + BD de tests + S3 local
make doctor                 # todo en ✅
```

Requisitos en el host: Docker con Compose v2, Make, `openssl` y `git`. **No hace
falta PHP ni Composer en el host**: todo corre en contenedores.

## 1. `make init`

```bash
make init NAME=miapp [PORT_BASE=8100] [TITLE="Mi App"] [VENDOR=miorg] [README=1] [YES=1]
```

| Variable | Qué es | Por defecto |
|---|---|---|
| `NAME` | Slug del proyecto: minúsculas, números y `-`, empieza por letra, 2-24 caracteres. No puede ser `apygg`. | obligatorio |
| `PORT_BASE` | Primer puerto de un bloque de 10 consecutivos. | primer bloque libre desde 8100 |
| `TITLE` | Nombre visible (ficha de `/` y título de `/docs/api`). | el slug |
| `VENDOR` | Parte `vendor/` del nombre en `composer.json`. | el slug |
| `README=1` | Crea `README.project.md` con un README mínimo del proyecto. | no |
| `YES=1` | No pide confirmación si ya estaba inicializado. | pide |

### Prefijo de versión (opcional)

Si el proyecto alimenta **apps móviles**, decide ya si la API cuelga de `/v1`: pon `API_PREFIX=v1` en el `.env` (y en el de
producción) antes de publicar la primera app; después cambiarlo rompe a los clientes. Por defecto va vacío y nada cambia.
Si lo activas, repasa `GOOGLE_REDIRECT_URI` y similares (llevan el prefijo), pasa `API_PREFIX` a `make e2e`/`make frontend-e2e`
y a `LOAD_API_PREFIX` en k6, y regenera el contrato (`make openapi`). Qué queda fuera y la política de cambios:
`docs/api-routes-conventions.md` («Versiones y apps móviles»).

### Nomenclatura y puertos

Todo sale de dos valores de `compose.env`: **`PROJECT`** (nombres) y **`PORT_BASE`/los `*_PORT`**
(puertos; `make init` los escribe). Los recursos Docker se llaman `<PROJECT>-<servicio>`
(guion, nunca guion bajo) y la BD `<NAME>` (guiones → `_`). Boilerplate (`PROJECT=apygg`) frente a
`make init NAME=clinkuna PORT_BASE=8100`:

| Qué | Boilerplate (`apygg`) | Proyecto (`clinkuna`) |
|---|---|---|
| Contenedores | `apygg-app`, `apygg-postgres`, `apygg-redis`, `apygg-horizon`, `apygg-reverb`, `apygg-scheduler`, `apygg-pgbouncer`, `apygg-meili`, `apygg-mailpit`, `apygg-s3`, `apygg-s3-init` | `clinkuna-app`, `clinkuna-postgres`, `clinkuna-redis`, `clinkuna-horizon`, `clinkuna-reverb`, `clinkuna-scheduler`, `clinkuna-pgbouncer`, `clinkuna-meili`, `clinkuna-mailpit`, `clinkuna-s3`, `clinkuna-s3-init` |
| Imágenes construidas | `apygg-app`, `apygg-horizon`, … | `clinkuna-app`, `clinkuna-horizon`, … |
| Volúmenes | `apygg-pgdata`, `apygg-redisdata`, `apygg-meilidata`, `apygg-s3data` | `clinkuna-pgdata`, `clinkuna-redisdata`, `clinkuna-meilidata`, `clinkuna-s3data` |
| Red | `apygg-network` | `clinkuna-network` |
| Base de datos | `apygg` (y `apygg_test`) | `clinkuna` (y `clinkuna_test`) |
| Puertos | bloque `8010`–`8019` | bloque propio `8100`–`8109` |

Puertos por servicio (host; valor del boilerplate → proyecto con `PORT_BASE=8100`):

| Variable en `compose.env` | Servicio | apygg | clinkuna |
|---|---|---|---|
| `APP_PORT` | app (API) | 8010 | 8100 |
| `POSTGRES_PORT` | PostgreSQL | 8011 | 8101 |
| `REVERB_PORT` | Reverb (WebSocket) | 8012 | 8102 |
| `REDIS_PORT` | Redis | 8013 | 8103 |
| `MEILISEARCH_PORT` | Meilisearch | 8014 | 8104 |
| `PGBOUNCER_PORT` | PgBouncer | 8015 | 8105 |
| `MAILPIT_SMTP_PORT` | Mailpit SMTP | 8016 | 8106 |
| `MAILPIT_UI_PORT` | Mailpit web | 8017 | 8107 |
| `S3_PORT` | S3 local (API) | 8018 | 8108 |
| `S3_CONSOLE_PORT` | S3 local (consola) | 8019 | 8109 |

Los puertos publicados de `app`, `reverb`, `pgbouncer` y `meilisearch` escuchan **solo en `127.0.0.1`** por defecto (como
Postgres, Redis, Mailpit y S3). Para abrirlos a la red pon `BIND_HOST=0.0.0.0` en `compose.env` y protégelos con firewall.
`make e2e`, `frontend-e2e` y `load` no se ven afectados (usan su propio compose o `127.0.0.1`).

Como cada proyecto tiene su `PROJECT` y su bloque de puertos, **varios proyectos conviven en el
mismo servidor sin chocar** (ni contenedores, ni volúmenes, ni red, ni puertos). Cambiar `PROJECT`
a mano sin cambiar los puertos sí colisiona. `make validate` comprueba que `compose.env` sea
coherente y `make doctor` avisa si un proyecto derivado sigue con `PROJECT=apygg` o si un puerto
está ocupado.

Para proyectos creados antes de este cambio (nombres con guion bajo): `docs/upgrading.md`
(sección «Nomenclatura Docker», `make rename-volumes`).

### Qué genera

- **`compose.env`** (Docker): `PROJECT=<slug>`, `USER_ID`/`GROUP_ID` del host, `DEV=true`,
  contraseñas aleatorias de BD, Redis, S3 y Meilisearch, y los puertos del bloque:

  | Offset | Servicio | Offset | Servicio |
  |---|---|---|---|
  | +0 | app | +5 | pgbouncer |
  | +1 | postgres | +6 | mailpit (SMTP) |
  | +2 | reverb | +7 | mailpit (web) |
  | +3 | redis | +8 | S3 (API) |
  | +4 | meilisearch | +9 | S3 (consola) |

  Para elegir bloque mira los puertos que escuchan (`ss`) **y** los `compose.env` de las
  carpetas vecinas (proyectos hermanos aunque estén apagados).
- **`.env`** (Laravel): `APP_NAME`, `APP_TITLE`, `APP_URL` con tu puerto, `APP_KEY` y
  `JWT_SECRET` (generados con `openssl`, sin PHP), `DB_*` coherentes con `compose.env`
  (la BD y el usuario son el slug con `_` en vez de `-`), claves de Reverb, buckets
  `<slug>` y `<slug>-private` con sus credenciales S3, correo apuntando a Mailpit,
  `ADMIN_EMAIL=admin@<slug>.test` y `ADMIN_PASSWORD` aleatoria.
- La contraseña del admin se imprime **una sola vez** al final de `make init` (y queda
  en `.env`).

Los permisos de ambos archivos quedan en `600`, y los dos están en `.gitignore`.

### Qué renombra (y qué no)

| Se cambia | Cómo |
|---|---|
| `composer.json` → `name` (`<vendor>/<slug>`) y `description` | edición de esas dos líneas |
| `package.json` → `name` | se añade o se cambia |
| Ficha de la raíz `/` y título de `/docs/api` ("APYGG API") | ahora salen de `config('app.title')` ← `APP_TITLE` del `.env`; no se edita PHP |
| Remoto git `origin` (si apunta a APYGG) | se renombra a `upstream`, ver abajo |

**No se toca** la documentación del boilerplate (`README.md`, `AGENTS.md`, `docs/`…),
para que `git merge upstream/main` no genere conflictos innecesarios. Con `README=1` se
crea `README.project.md` aparte; si quieres que sea tu README principal, renómbralo tú
(`git mv`) y en los merges resuelve ese archivo con `git checkout --ours README.md`.

Tras renombrar el paquete, el `content-hash` de `composer.lock` queda viejo; `make setup`
lo actualiza solo (`composer update --lock`, no cambia versiones).

### Git

Si `origin` apunta a un repo `.../apygg`, `make init`:

1. lo renombra a **`upstream`** y desactiva su push (`NO_PUSH_AL_BOILERPLATE`), para que
   nadie publique por error en el boilerplate;
2. quita el tracking de la rama actual.

Después añade el origin de **tu** proyecto (no se crea ningún repo ni se hace push):

```bash
git remote add origin git@github.com:miorg/miapp.git
git push -u origin main
```

### Idempotencia

Repetir `make init NAME=miapp` conserva contraseñas, claves, puertos y título (guarda
copia `compose.env.bak-<fecha>` y `.env.bak-<fecha>`) y pide confirmación. Con otro
`NAME` genera secretos nuevos: si ya habías levantado el stack, haz antes `make down-v`.

## 2. `make setup`

Hace, en orden: valida `compose.env` → build → arranca postgres, redis y app → espera salud
→ espera a que la app instale `vendor/` la primera vez → `composer install` → arranca el
resto de servicios (reverb, horizon, scheduler y, con dev, Mailpit y S3) → `migrate` y
`db:seed` → `openapi:export` (regenera `docs/openapi.json` con el título de tu proyecto: sale de `APP_TITLE`, y el test del contrato exige que coincida; **commitea ese cambio**) → BD de tests (`make testdb-migrate`) → `storage:link` → buckets S3 locales →
espera `/health`. Al final imprime las URLs y las credenciales del admin:

```
API:         http://localhost:8100        Docs API:  /docs/api      Back-office: /backoffice
Mailpit:     http://localhost:8107        Consola S3: http://localhost:8109
Admin:       admin@miapp.test / <ADMIN_PASSWORD de .env>
```

El perfil **dev** (Mailpit + emulador S3, ver `docs/local-services.md`) se activa con
`DEV=true`, que `make init` deja en `compose.env`. Para un setup sin ellos:
`make setup DEV=false`. Es re-ejecutable: tras un fallo a medias, repite `make setup`.

## 3. `make doctor`

Solo lee, no cambia nada, y sale con código ≠ 0 si algo falla. Cada ❌ lleva debajo la
forma de arreglarlo (`→ ...`). Comprueba:

- Docker y Compose v2 disponibles y el demonio respondiendo.
- `compose.env` y `.env` existen; `PROJECT` no es `apygg` en un proyecto derivado.
- `.env` coherente con `compose.env` (BD, usuario, contraseñas, puerto de Reverb, S3).
- `APP_KEY` y `JWT_SECRET` presentes.
- Puertos de `compose.env` no ocupados por otro proceso.
- Contenedores del proyecto arriba y sanos.
- BD de desarrollo y de tests existen y están migradas.
- `bootstrap/cache/packages.php` no lista paquetes que ya no están en `vendor/` (lo que
  pasó al quitar Socialite: "Class ... ServiceProvider not found").
- `vendor/` coincide con `composer.lock`.

`make validate` (lo ejecutan `build` y `up`) comprueba aparte `compose.env`: `PROJECT`
obligatorio y válido, puertos numéricos y sin repetir, `REDIS_PASSWORD` no vacío (el
compose arranca Redis con `--requirepass`) y que BD, Redis, S3 y Mailpit solo se
publiquen en `127.0.0.1`.

## 4. Primeros pasos

```bash
curl -s http://localhost:8100/health
curl -s -X POST http://localhost:8100/auth/login -H 'Content-Type: application/json' \
  -d '{"login":"admin@miapp.test","password":"<ADMIN_PASSWORD>"}'
```

- `make fresh` rehace la BD de desarrollo (`migrate:fresh --seed`); pide confirmación
  (`YES=1` la salta) y se niega con `ENV=prod` o `APP_ENV=production`.
- `make test`, `make check`, `make help`.
- Lee `AGENTS.md` y los documentos obligatorios que enlaza antes de añadir código.
- Para **producción** no uses los valores de `init` tal cual: define `APP_ENV`, S3 y
  correo reales, y no actives `DEV`.

## 5. Recibir mejoras del boilerplate

```bash
make upgrade-check                     # trae upstream/main y lista lo que te falta
git switch -c actualizar-boilerplate
git fetch upstream && git merge upstream/main
```

`make upgrade-check` solo hace `git fetch` y muestra: commits nuevos, archivos sensibles
que cambiaron (migraciones, `.env.example`, compose, composer, make) y los que cambiaron
en ambos lados (conflictos probables). Después del merge lee `docs/upgrading.md` (qué
rompe y qué acción exige) y `CHANGELOG.md`, y corre `make composer cmd=install`,
`make migrate`, `make check`, `make test` y `make doctor`. Haz `make backup` antes.

Variables nuevas de `.env.example`: añádelas a tu `.env` (no se sobrescribe solo).

Guía completa (conflictos típicos, módulos quitados, versionado por etiquetas): `docs/upgrading-from-boilerplate.md`.

## 6. Desarrollar el propio boilerplate con varios worktrees

Cuando varios agentes o ramas trabajan a la vez sobre APYGG (un `git worktree` por tarea),
cada uno necesita su propio stack para no pisar al principal:

```bash
git worktree add ../apygg-wt/mi-tarea -b mi-tarea
cd ../apygg-wt/mi-tarea
make wt-env NAME=mi-tarea           # compose.env + .env propios
cp -a ../../apygg/vendor vendor     # opcional: evita el composer install inicial
make setup                          # stack completo aislado
```

`make wt-env` (modo `--worktree` de `init-project.sh`) solo genera entorno: `PROJECT=apygg-<NAME>`
(contenedores, red y volúmenes propios), bloque de puertos desde 8200, BD `apygg_<NAME>` y
`apygg_<NAME>_test`, secretos aleatorios. **No** renombra composer/package ni toca git, y
`APP_NAME` sigue siendo `apygg` para que los tests no cambien. `make down-v` limpia ese
stack (y solo ese). Un stack completo cuesta ~1,5-2 GB de RAM: con poca memoria, levanta
uno o dos a la vez.

Alternativa ligera, si solo necesitas correr `make test`/`make check` y la RAM es poca:
un contenedor desechable por worktree contra el Postgres del stack principal, con BD
propia (`apygg_wt_<tarea>` y `_test`) y prefijo de Redis propio. Es un script local de
quien administra la máquina (`~/dev/apygg-wt/wt-run.sh`), no forma parte del repo.

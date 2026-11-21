# Pruebas de carga (k6)

Escenarios [k6](https://k6.io) en `load/` para medir latencia y errores bajo tráfico. Se ejecutan con la imagen
`grafana/k6` **de versión fija** (`K6_IMAGE`, por defecto `grafana/k6:1.4.0`) en un contenedor: no hay que instalar nada.
Los usuarios salen de [`seed:load`](seed-load.md) (CSV `storage/app/seed-load/credentials.csv`). Solo desarrollo y staging:
**nunca contra producción**. La campaña real (tamaños grandes, servidor dedicado) es un trabajo aparte; aquí está la herramienta
y un humo verificado.

## Escenarios

| Script | Objetivo | Perfil por defecto |
|---|---|---|
| `load/smoke.js` | ¿funciona todo y nada se degrada? | 3 VUs, 30 s, + 1 VU escribiendo + 1 VU presignando + 3 logins |
| `load/load.js` | tráfico esperado sostenido | rampa 1 min a `LOAD_VUS` (20), 5 min, baja 30 s; 1 login cada 5 s |
| `load/stress.js` | punto de degradación | escalones x1, x2, x4, x6 de `LOAD_VUS`, 2 min cada uno; aborta si los errores pasan de 15 % |
| `load/spike.js` | salto brusco y recuperación | `LOAD_VUS` -> x10 en 10 s, 1 min arriba, vuelve y se observa 2 min |
| `load/soak.js` | fugas y degradación lenta | `LOAD_VUS` constantes 2 h (`LOAD_SOAK_DURATION`) |

Los flujos (`load/lib/flows.js`) son los mismos en todos; cada VU es un usuario **dueño** de una empresa sembrada, con su token
(se renueva solo al recibir 401) y su cabecera `X-Tenant`:

- **login**: `POST /auth/login` (etiqueta `endpoint:login`).
- **lectura** (`kind:read`): `GET /tenant/members` (paginado, página al azar), `GET /tenant/billing/invoices`, `GET /user/notifications`
  y `/user/notifications/unread-count` (bandeja), `GET /files`.
- **escritura con idempotencia** (`kind:write`, pausa `LOAD_INVITE_PACING` = 5 s por usuario: cada intento son 2 peticiones y la ruta limita a 30/min): `POST /tenant/invitations` con `Idempotency-Key` y **la misma petición repetida**, que debe
  devolver 201 con `Idempotent-Replayed: true` (comprueba que el reintento no crea otra).
- **subida presignada**: `POST /files/uploads` (paso 1: intención, cuota y URL firmada; no toca el bucket ni sube bytes).
- **mezcla** (carga, estrés, pico, resistencia): `LOAD_WRITE_RATIO` (0,2) de las iteraciones escriben; el resto navega.

## Umbrales

Definidos en los scripts (`thresholds()` de `load/lib/config.js`); si alguno falla, k6 sale con código 99 y `make` falla.

| Métrica | humo / carga / resistencia | estrés y pico |
|---|---|---|
| `http_req_failed` | < 1 % (`LOAD_MAX_ERROR_RATE`) | < 15 % (estrés, con aborto) / < 10 % (pico) |
| `checks` | > 99 % | > 90 % |
| p95 de lecturas (`kind:read`) | < 800 ms (`LOAD_P95_READ_MS`) | < 3 000 ms |
| p95 de escrituras (`kind:write`) | < 1 500 ms (`LOAD_P95_WRITE_MS`) | < 5 000 ms |
| p95 de `login` | < 2 000 ms (`LOAD_P95_LOGIN_MS`; bcrypt es lento a propósito) | < 5 000 ms |

Son umbrales de **base razonable para un servidor modesto**: ajústalos por proyecto con las variables, no editando los scripts.

## Cómo ejecutarlos

```bash
make seed-load TENANTS=500 USERS_PER_TENANT=20     # una vez: datos + credentials.csv
make load-smoke      LOAD_BASE_URL=http://127.0.0.1:8010
make load-test       LOAD_VUS=50 LOAD_HOLD=10m
make load-stress | load-spike | load-soak
make load-report                                    # tabla con p95, errores y umbrales de load/results/*.json
```

- `LOAD_BASE_URL` por defecto `http://127.0.0.1:<APP_PORT>`; k6 corre con `--network host`.
- Cualquier variable `LOAD_*` de `load/lib/config.js` se pasa al contenedor (`LOAD_VUS`, `LOAD_ROLES`, `LOAD_THINK_TIME`, `LOAD_CREDENTIALS`...).
- Cada ejecución deja `load/results/<escenario>.json` (resumen y métricas; no se versiona).
- **El entorno bajo prueba debe relajar el rate limiting**: todo el tráfico sale de una IP y de pocos usuarios, así que el 429 de login (5/min por IP)
  y los de lectura/escritura (60 y 30/min por usuario) falsearían la medida. Usa `RATE_LIMIT_AUTH_MAX`, `RATE_LIMIT_READ_MAX`, `RATE_LIMIT_WRITE_MAX`
  y `RATE_LIMIT_ADMIN_MAX` (`config/rate-limiting.php`; mismos valores por defecto). Los `throttle:N,M` fijos de rutas concretas (invitaciones 30/min
  por usuario, facturación 10/min) siguen vigentes: con más VUs que usuarios sembrados verás 429 ahí, es el límite real.
- Con `MULTI_TENANCY_ENABLED=false` los datos sembrados no se aíslan por empresa y los flujos `/tenant/*` no aplican: actívalo.
- Las invitaciones se encolan (`QUEUE_CONNECTION=redis`): sin Horizon se acumulan en Redis; con Horizon, el correo se escribe (`MAIL_MAILER=log`). No pruebes con correo real.

## Stack temporal para el humo (`make load-stack-smoke`)

`load/compose.yml` + `load/stack.sh` levantan un stack **propio y desechable** (PostgreSQL, Redis y la API con Octane; sin Horizon ni Reverb ni S3):
nombre de proyecto único (`LOAD_PROJECT`), un puerto alto solo en `127.0.0.1` (`LOAD_PORT`, 18010), **≤ 2 GB** de memoria en total
(640 MB + 192 MB + 1,1 GB) y reutilizando las imágenes ya construidas (`LOAD_APP_IMAGE`, `LOAD_POSTGRES_IMAGE`, `LOAD_REDIS_IMAGE`; por defecto las de `apygg`:
en un derivado, pon `<proyecto>-app:latest`...). Configura el rate limiting alto, multiempresa y equipos de permisos activos, pasarela `fake`, discos
`s3`/`s3_private` con un endpoint inexistente (el presign se calcula sin red) y `fsync=off` en PostgreSQL (solo pruebas).

`load/verify.sh` (lo que hace `make load-stack-smoke`) encadena: `up` -> `migrate --seed` -> `seed:load --tenants=$LOAD_TENANTS` (20) -> k6 -> `down -v`, y borra
contenedores, volumen y red aunque algo falle (`trap`). En un servidor compartido ejecútalo bajo el candado de verificaciones:

```bash
LOAD_PROJECT=apygg-k6 LOAD_PORT=18010 flock /home/ubuntu/dev/apygg-wt/.verify.lock load/verify.sh
```

## Campaña y línea base

`load/campaign.sh` encadena el stack temporal, la siembra y los escenarios con muestreo de memoria/conexiones y candado, y `load/stack.sh app pgbouncer`
mide con PgBouncer delante. Las cifras medidas y cómo repetirlas están en [`performance-baseline.md`](performance-baseline.md).
Para una resistencia limpia usa `LOAD_WRITE_RATIO=0.05`: con las escrituras por defecto el flujo de invitaciones agota el tope de invitaciones pendientes de la empresa
(`422 invitation_limit_reached`).

## Cómo leer un resultado

- Un umbral rojo señala **qué** falló (`http_req_duration{kind:write}`...): mira primero la etiqueta `endpoint` en el JSON de `load/results/`.
- En estrés, el escalón donde empiezan los errores o el p95 se dispara es el punto de degradación; compáralo con `LOAD_WORKERS` de Octane, el pool de BD y Redis.
- En resistencia, vigila que el p95 y la memoria del contenedor **no suban** con el tiempo (`docker stats`).
- Una BD sembrada con pocos datos no representa los planes de consulta de una grande: para cifras creíbles siembra miles de empresas y ejecuta `ANALYZE`.

## Riesgos y límites

- La API en el servidor compartido compite por CPU con otros proyectos: tómalo como cota pesimista; un resultado único no es un benchmark.
- Las escrituras de carga **crean datos** (invitaciones, intenciones de subida con cuota reservada): usa un entorno desechable y limpia con `seed:load:purge` o `down -v`.
- El presign solo prueba el paso 1; subir bytes reales (PUT al bucket + `complete`) necesita S3 y se mide aparte.
- k6 no hace pruebas funcionales: los `checks` solo detectan respuestas inesperadas bajo carga.

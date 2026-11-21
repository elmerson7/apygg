# Línea base de rendimiento

Campaña de carga modesta con los escenarios k6 de `load/` ([`docs/load-testing.md`](load-testing.md)) y la siembra de
[`seed:load`](seed-load.md). Sirve de **suelo de referencia**: si un cambio empeora estas cifras en las mismas condiciones, se
nota; no es una promesa de capacidad de producción.

## Condiciones (léelas antes de usar las cifras)

- **Servidor compartido**: 8 vCPU, ~23 GB de RAM, swap lleno y *load average* de 20 por otros proyectos y otras verificaciones
  corriendo a la vez. Las cifras son un **suelo, no un techo**, y llevan mucho ruido: la misma configuración (`LOAD_WORKERS=2`,
  carga, 20 VUs) dio p95 de 278, 391 y 506 ms en tres pasadas. **Solo se comparan diferencias grandes** (x2 o más).
- Commit `052c34e` (rama `rp/carga`), Laravel 13, PHP 8.5, FrankenPHP/Octane, PostgreSQL 18, Redis 7.
- Stack temporal `load/compose.yml` (≤ 2 GB: app 1,1 GB, PostgreSQL 640 MB con `fsync=off`, Redis 192 MB; PgBouncer 64 MB opcional),
  pasarela `fake`, rate limiting alto, multiempresa y equipos activos, `--max-requests=500`.
- k6 1.4.0 en el mismo servidor (`--network host`): comparte CPU con la API, otro motivo para tomarlo como cota pesimista.
- Siembra: **200 empresas** (10 usuarios y 5 archivos por empresa; 3 s, BD de 18 MB) y, para el cotejo de planes, **3 000 empresas**
  (15 000 usuarios, 10 238 facturas, 29 618 avisos; 22 s, BD de 53 MB). Todo `ANALYZE`d.
- Perfiles usados: carga 20 VUs (30 s de subida + 3 min), estrés 8 -> 16 -> 32 -> 48 VUs (90 s por escalón), pico 5 -> 50 VUs
  (1 min arriba), resistencia 20 VUs 15 min. Ritmo de escrituras y umbrales: los de `docs/load-testing.md`.

## Resultados por escenario (`LOAD_WORKERS=2`, conexión directa a PostgreSQL)

| Escenario | Peticiones | req/s | Errores HTTP | p95 | p99 | Umbrales |
|---|---|---|---|---|---|---|
| humo (3 VUs, 30 s) | 1 335 | 33,7 | 0,07 % | 718 ms | 883 ms | OK |
| carga (20 VUs, 3 min) | 9 438 | 38,8 | 0,45 % | 278 ms | 425 ms | OK |
| estrés (hasta 48 VUs) | 14 379 | 34,2 | 0,85 % | 684 ms | 1 071 ms | OK |
| pico (5 -> 50 VUs, 1 min) | 6 292 | 22,4 | 0,99 % | 938 ms | 2 194 ms | OK |
| resistencia (15 min, 20 VUs, 20 % de escrituras) | 38 400 | 42,4 | 2,20 % | 329 ms | 526 ms | FALLAN |
| resistencia (15 min, 5 % de escrituras) | 40 703 | 45,1 | 0,12 % | 399 ms | 576 ms | FALLAN (solo `login`) |
| carga con 3 000 empresas | 8 527 | 35,5 | 0,46 % | 373 ms | 528 ms | OK |

Lecturas honestas de lo que dicen:

- **El estrés no llegó a degradar**: con el tope de 48 VUs el p95 quedó en 684 ms y los errores en 0,85 %; no se encontró el punto de
  rotura (la regla era parar en 50 VUs en un servidor compartido). El pico de 50 VUs subió el p95 a ~0,9 s y el p99 a 2,2 s, y se recuperó
  al bajar.
- **Errores de la resistencia (2,2 %) no son de rendimiento**: son respuestas de dominio correctas que k6 cuenta como fallo HTTP:
  `422 invitation_limit_reached` (el flujo de invitaciones se come el tope de invitaciones pendientes de cada empresa en 15 min; con
  `LOAD_WRITE_RATIO=0.05` desaparece) y `402 subscription_inactive` (empresas sembradas con suscripción caída, en solo lectura). Con
  menos escrituras la resistencia quedó en 0,12 %.
- **`login` hace saltar el umbral de la resistencia** (p95 3,4-4,5 s frente a 2 s): el JWT dura 15 min (`JWT_TTL=15`) y la prueba
  dura 15 min, así que los 20 usuarios caducan a la vez y reinician sesión juntos (bcrypt es lento a propósito) con 2 workers. Es un
  artefacto de la prueba; en producción los reinicios se reparten. Ver "Mejoras futuras" en las recomendaciones.
- Conteo de consultas: ~20 por petición, **sin N+1** (las consultas de listados salen una vez por petición) y con índices usados también con
  3 000 empresas (`EXPLAIN`: `subscriptions_tenant_id_status_index`, `invoices_tenant_id_status_index`, `inbox_notifications_tenant_id_user_id_created_at_index`, `tenants_pkey`; 0,2-0,4 ms
  cada una). No se encontró ningún endpoint lento, así que **no se cambió código ni se añadió ninguna migración**.

## Workers de Octane (carga, 20 VUs, 3 min)

| `LOAD_WORKERS` | req/s | p95 | p99 | Errores | Umbrales | Memoria máx. del contenedor |
|---|---|---|---|---|---|---|
| 1 | 21,2 | 984 ms | 1 180 ms | 0,49 % | FALLAN (p95 lecturas 977 ms) | 257 MiB |
| 2 | 38,8 / 33,2 / 34,3 (3 pasadas) | 278 / 506 / 391 ms | 425-855 ms | 0,37-0,60 % | OK | 280 MiB |
| 4 | 38,0 | 317 ms | 521 ms | 0,37 % | OK | 347 MiB |

- **1 worker** limita el rendimiento a la mitad (21 req/s) y rompe el umbral de lectura: no es suficiente ni con 20 VUs.
- **2 y 4 workers** rinden igual (~38 req/s): el cuello es la CPU compartida (y el *think time* de k6), no el número de workers; 4 solo
  gasta ~65 MiB más.
- **Memoria**: ~100 MiB por worker (contenedor entero 160-280 MiB con 2 workers). En la resistencia de 15 min la media de la memoria por
  quintos fue **193 / 193 / 194 / 194 / 194 MiB** (y 190 / 188 / 189 / 189 / 189 en la segunda): **sin fuga**; `--max-requests=500` recicla cada
  worker. CPU del contenedor hasta 170-240 % en pico (2 workers).

## PgBouncer (modo `transaction`, carga, 2 workers)

| Modo | req/s | p95 | p99 | Errores | Conexiones a PostgreSQL (máx.) |
|---|---|---|---|---|---|
| directo | 33,2 / 34,3 | 506 / 391 ms | 855 / 598 ms | 0,60 / 0,37 % | 3 |
| con PgBouncer | 24,9 | 738 ms | 1 027 ms | 0,30 % | 3 |

- **Funciona con Laravel + pgsql** sin tocar `config/database.php`: PgBouncer 1.25 (`max_prepared_statements` = 200 por defecto desde
  1.21) reenvía los *prepared statements* de PDO y no hubo errores nuevos. Pasa por `auth_type = scram-sha-256` con `userlist.txt`
  (PostgreSQL 18 guarda SCRAM; con `md5` fallaría).
- **Con 2 workers no hay nada que agrupar**: la API mantiene 1-3 conexiones con o sin pooler. El PgBouncer añadió un salto de red y salió algo más lento
  (dentro del ruido de las pasadas, pero sin ninguna ganancia). Su valor aparece con **muchas conexiones cliente**: varias réplicas de API x workers
  x (futuro) Horizon, que sin pooler multiplican las conexiones hasta toparse con `max_connections`.
- La imagen `pgbouncer/pgbouncer` solo traduce las variables `PGBOUNCER_*` que lista su `entrypoint.sh`; `max_prepared_statements` **no** está entre
  ellas (usa el valor por defecto de la versión). Si lo necesitas distinto, monta tu propio `pgbouncer.ini`.
- Con PgBouncer en transaction pooling **no uses** `SET`/`LISTEN`/*advisory locks* de sesión ni transacciones que dependan de la sesión. En la base no hay
  ninguno (`SafeMigration` usa `SET` de sesión solo en migraciones: córrelas contra PostgreSQL directo, no contra el pooler).

## Redis

- Memoria usada en toda la campaña: **2,5-7 MB** (pico 6,8 MB, de 256 MB), 0 claves expulsadas. `db0` (rate limit, bloqueos, estado general) ~225 claves,
  `db1` (caché) ~95, `db2` (colas) 2.
- **Reparto**: caché, colas y sesiones comparten una instancia con `--maxmemory 256mb --maxmemory-policy allkeys-lru`. `allkeys-lru` puede **expulsar claves de la
  cola o bloqueos** si la caché crece: un trabajo en cola podría perderse. Con la memoria que se mide hoy (< 10 MB) no ocurre, pero en producción con volumen
  conviene **separar** (una instancia con `noeviction` para colas, bloqueos e idempotencia, otra `allkeys-lru` para caché) o al menos subir `maxmemory`
  muy por encima del uso y vigilar `evicted_keys`. No se tocó la configuración: la medición no lo justifica todavía.
- Los *rate limiters* de `RATE_LIMIT_*_MAX` ocupan `db0` y caducan solos.

## Recomendaciones de producción

- **Workers de Octane**: `OCTANE_WORKERS` (nuevo en `docker/app/entrypoint.sh`, por defecto `auto` = nº de CPU). Pauta: **1 a 2 workers por vCPU**
  y ~100-150 MiB de RAM por worker; con 2 vCPU, 2-4 workers. 1 worker en total es insuficiente. Mantén `OCTANE_MAX_REQUESTS=500` (valor por defecto de Octane, ahora
  explícito y configurable): el uso de memoria se mantuvo plano.
- **PgBouncer**: no hace falta con una sola réplica y pocos workers. Actívalo (perfil `prod` de `docker-compose.yml`) cuando
  `réplicas x workers x (jobs + web)` se acerque a `max_connections` de PostgreSQL o haya muchas réplicas; `default_pool_size` ~ 2-3 x vCPU de la BD, `max_client_conn` por encima de
  `réplicas x workers`. Usa `scram-sha-256` con PostgreSQL 18 y mantén las migraciones en conexión directa.
- **`max_connections` de PostgreSQL**: 100 sobra para una réplica (se vieron 1-5 conexiones). Calcula `réplicas x (workers + 2) + Horizon + reserva`.
- **Redis**: separar colas/bloqueos de la caché (arriba) cuando haya volumen; vigilar `evicted_keys` y `used_memory`.
- **Mejoras futuras sin urgencia** (no hechas aquí): `logs_api` inserta una fila por petición de forma síncrona (la consulta que más tiempo total acumula, 0,2 ms) y cada
  petición autenticada hace ~8 consultas fijas (tenant x2, suscripción, roles, usuario): candidatas a cola/muestreo y a caché corta si el servidor llega a saturarse.
  Si el JWT y los tests de resistencia coinciden, escalona el login de los VUs.

## Cómo repetir la campaña

Siempre con el candado de verificaciones del servidor compartido (el script lo toma por cada medición) y el stack temporal (`down -v` al terminar):

```bash
export LOAD_PROJECT=apygg-carga LOAD_PORT=18010
trap 'load/stack.sh down' EXIT
load/campaign.sh up                                           # stack + migraciones
load/campaign.sh seed --tenants=200 --users-per-tenant=10 --files-per-tenant=5
LOAD_VUS=20 LOAD_RAMP=30s LOAD_HOLD=3m load/campaign.sh run load-w2 load 2        # etiqueta, escenario, workers
LOAD_VUS=20 LOAD_RAMP=30s LOAD_HOLD=3m load/campaign.sh run load-w2-pgb load 2 pgbouncer
LOAD_VUS=8 LOAD_STEP=90s load/campaign.sh run stress stress 2
LOAD_VUS=5 load/campaign.sh run spike spike 2
LOAD_VUS=20 LOAD_SOAK_DURATION=15m LOAD_WRITE_RATIO=0.05 load/campaign.sh run soak soak 2
```

- `load/campaign.sh run` recrea la API con los workers y el pooler pedidos, ejecuta el escenario y deja en `load/results/campaign/<etiqueta>.{json,log,stats.csv}` el
  resumen k6 y una muestra cada 10 s de memoria/CPU del contenedor, conexiones de PostgreSQL (total y activas), memoria de Redis y RSS de los procesos.
- `load/stack.sh sql "<consulta>"` abre `psql` en el stack (`pg_stat_statements` ya está activo: `select calls, total_exec_time, query from pg_stat_statements order by 3 desc`).
- `load/stack.sh app pgbouncer` pone PgBouncer delante (servicio `pgbouncer`, perfil `pgbouncer` de `load/compose.yml`); `load/stack.sh app direct` lo quita.
- Repite al menos tres veces cada configuración que compares y desconfía de diferencias menores a ~2x en este servidor compartido.

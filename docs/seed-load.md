# Datos masivos de prueba (`seed:load`)

Genera miles de empresas con miembros, suscripciones, facturas, pagos, archivos y avisos para **pruebas de volumen y de carga**
(`docs/testing.md`; los escenarios k6 reutilizan sus usuarios). Solo desarrollo y staging: **se niega a correr en producción**.
No es `DemoDataSeeder` (6 usuarios para ver la app) ni `DatabaseSeeder` (datos seguros de cualquier entorno).

```bash
make seed-load TENANTS=500 USERS_PER_TENANT=20 FILES=10 SEED=1     # = php artisan seed:load ...
make seed-load-purge                                               # = php artisan seed:load:purge --force
```

| Opción | Por defecto | Qué hace |
|---|---|---|
| `--tenants` | 500 | empresas a crear |
| `--users-per-tenant` | 20 | usuarios por empresa (1 dueño, ~10 % admins, resto miembros) |
| `--files-per-tenant` | 10 | filas de `files` por empresa (solo metadatos, **sin binarios**) |
| `--notifications-per-user` | 2 | avisos en la bandeja |
| `--seed` | 1 | semilla del generador |
| `--as-of` | ahora | fecha de referencia ISO 8601; fíjala para obtener datos idénticos |
| `--batch` | 100 | empresas por lote (una transacción por lote) |
| `--password` | `password` | contraseña de todos los usuarios generados |
| `--credentials`, `--credentials-limit` | `storage/app/seed-load/credentials.csv`, 200 | CSV `tenant_id,tenant_slug,email,password,tenant_role` (dueño y último miembro de las primeras N empresas) para k6 (`0` = no escribirlo) |

Volver a ejecutarlo **añade**: continúa la numeración (`ld-0000501`...). Con `MULTI_TENANCY_ENABLED=false` los datos se crean igual,
pero la API no los aísla por empresa: actívalo para recorrerlos por `X-Tenant`.

## Qué genera y qué invariantes respeta

Todo se inserta en bloque con el query builder (sin Eloquent, eventos ni observers), pero con las mismas reglas que el dominio:

- **Empresas** `ld-NNNNNNN` (`settings.seed_load = true`), 97 % `active` / 3 % `suspended`, con `plan`, `trial_ends_at` y moneda coherentes con su suscripción.
- **Usuarios** `u<k>.<slug>@seed-load.test` con email verificado, contraseña común (se calcula **un** hash, no uno por usuario) y rol global `user`.
- **Membresías** `tenant_user`: un único `owner` activo por empresa, `admin` y `member`; 2 % de los miembros raros suspendidos y ~3 % pertenecen también a otra empresa del lote (el caso que más fácilmente mezcla datos). Cada miembro recibe el rol de Spatie de su rol (`tenant-owner`...; con `PERMISSION_TEAMS=true`, en el equipo del tenant).
- **Suscripciones** (una viva como máximo por empresa; precio, plan, moneda e intervalo copiados del catálogo, `billing:sync-plans` se ejecuta antes): gratuita, en prueba, activa (mensual/anual, 1-12 periodos de historial), `past_due` (periodo viejo + factura nueva abierta + intento fallido, como `RenewalService`), cancelada (al fin de periodo o por impago agotado con factura `uncollectible`), `incomplete` y empresas sin suscripción. Periodos con `PeriodCalculator`.
- **Facturas** una por periodo, con una línea, `Money` en unidad menor (`BILLING_TAX_RATE` aplicado una vez sobre el subtotal, mitad hacia fuera de cero; `total = subtotal + impuesto`), `number_scope` según `BILLING_INVOICE_SEQUENCE_SCOPE`.
- **Numeración sin huecos**: por lote se reserva un rango contiguo de la secuencia `billing_invoice` (bloqueo de fila y `next_value`, igual que `SequenceInterface`), por año si la serie reinicia, y se reparte en orden cronológico; con serie `tenant`, una por empresa. Una factura real posterior toma el número siguiente. Los números son contiguos, pero **no cronológicos entre lotes**.
- **Pagos**: una factura `paid` tiene exactamente un intento `succeeded` por su total; `open`/`uncollectible` solo intentos `failed` (pasarela `fake`, claves `inv-<id>-a<n>`).
- **Archivos**: metadatos con `tenant_id` y subidos por miembros de esa empresa; ruta `seed-load/<tenant>/...`, disco `local` (no existen en disco).
- **Bandeja** (`inbox_notifications`) con `tenant_id` y usuario de la empresa.

No genera: auditoría, logs, API keys, webhooks, invitaciones ni correos.

**Reproducible**: cada empresa sale de su propio `Mt19937(seed, índice)`; los mismos `--seed`, tamaños y `--as-of` dan las mismas empresas, usuarios, planes y totales aunque cambie `--batch` (los números de factura coinciden con el mismo `--batch`).

## Limpiar

`seed:load:purge` borra solo lo generado (empresas `ld-*` con la marca, usuarios `@seed-load.test`, sus roles, suscripciones, facturas, pagos, archivos y avisos) y devuelve la serie global de facturas a `máximo de las que quedan + 1` (o la elimina si no queda ninguna), de modo que la numeración sigue sin huecos. Los borrados van por trozos de 200 empresas.

## Medidas (servidor compartido, PostgreSQL 18, contenedor de 2 GB)

Medido el 2026-11-20 con `--users-per-tenant=20 --files-per-tenant=10 --seed=1`, lotes de 100, en un contenedor limitado a 2 GB, contra el PostgreSQL compartido de desarrollo (CPU cargada por otros proyectos: tómalo como cota pesimista):

| Empresas | Filas (usuarios / facturas / archivos / avisos) | Tiempo | Memoria pico PHP | Pico del contenedor (cgroup) | Tamaño de BD | Borrado (`seed:load:purge`) |
|---|---|---|---|---|---|---|
| 500 | 10 000 / 1 667 / 5 000 / 19 646 | 51 s (~10 empresas/s) | 119 MB | 141 MB | 35 MB | 6,9 s |
| 2 000 | 40 000 / 6 989 / 20 000 / 78 580 | 3 min 24 s (~10 empresas/s) | 154 MB | 208 MB | 102 MB | 31,3 s |

El tiempo es lineal y la memoria casi plana (depende del tamaño del lote, no del total): miles de empresas en minutos sin acercarse al límite. Tras 2 000 empresas la numeración de facturas quedó sin huecos por año (1..N en cada serie). `--batch` mayor sube la memoria y baja poco el tiempo; el coste dominante es el volumen de filas insertadas (un hash de contraseña por ejecución, no por usuario).

## Riesgos y límites

- Las facturas históricas ocupan números de la serie **real**: úsalo solo en entornos de pruebas.
- `seed:load:purge` reajusta la serie global leyendo las facturas que quedan; no lo ejecutes mientras otro proceso emite facturas.
- Una BD con pocas filas no representa índices ni planes de consulta de una grande: tras cargar, `ANALYZE` (autovacuum lo hará solo, pero tarda).

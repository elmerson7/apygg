# Módulos: qué hay, cómo quitar uno y cómo añadir otro

APYGG es un boilerplate: lo que un SaaS no use se **quita con un comando**, y lo que falte se añade con el
mismo esqueleto. Un «módulo» es una pieza vertical (ruta → controller → servicio → modelo → permisos → tests →
doc). Cada módulo tiene un **manifiesto** `modules/<nombre>.php` que dice qué archivos le pertenecen, de qué
depende, qué tablas crea y qué permisos siembra. Referencias reales para imitar: **Archivos** (`files`),
**Webhooks** (`webhooks`), **Correo** (`mail`).

```bash
make module-list                          # módulos, estado (presente/quitado), dependencias
make module-remove name=billing DRY=1     # simulación: lo que borraría y editaría
make module-remove name=billing           # lo quita y verifica que la app arranca
make module-new name=mi-modulo            # esqueleto de un módulo nuevo
```

(`php artisan module:list | module:remove | module:new` por debajo; todo dentro del contenedor.)

## Mapa de módulos

**Núcleo** (no se quita sin rehacer el boilerplate): `auth` (JWT, sesiones, 2FA, API keys), `users`, `roles`
(Spatie), `tenancy` (multiempresa), `files`, `mail`, `notifications`, `audit`, `settings-flags` (ajustes, flags,
límites por plan, menú, i18n, dinero, numeraciones), `idempotency`, `logging`, `security`, `webhooks` (salientes: lo usan de recurso de ejemplo las pruebas de auditoría y
aislamiento y migraciones del núcleo tocan sus tablas), `inbound-webhooks` y
`plataforma` (base de controllers/modelos, proveedores, config, Docker, CI, scripts, docs).

**Opcionales** (se pueden quitar uno a uno):

| Módulo | Qué incluye | Depende de | Tablas | Apagarlo sin quitarlo |
|---|---|---|---|---|
| `llm` | Gateway de IA, áreas, auditoría de consumo | — | `llm_areas`, `logs_llm` | Sin `LLM_URL`/`LLM_API_KEY` usa `NullProvider` |
| `docs-assistant` | Asistente RAG sobre `docs/` | `llm` | `doc_chunks`, `doc_conversations` | Sin LLM responde solo con búsqueda |
| `backoffice` | Panel Filament (MFA) | — | — | `BACKOFFICE_ENABLED=false` |
| `billing` | Planes, suscripción, facturas, Stripe, conciliación, bloqueo y avisos | — | `plans`, `plan_prices`, `billing_accounts`, `subscriptions`, `invoices`, `invoice_lines`, `payments`, `payment_discrepancies`, `billing_notices` | `PAYMENTS_MODE=null`, `BILLING_ENFORCEMENT_ENABLED=false` |
| `export-import` | Exportar/importar CSV/XLSX con cola | — | `data_exports`, `data_imports` | — |
| `privacy` | Documentos legales, consentimientos, exportación y borrado de cuenta | — | `legal_documents`, `user_consents`, `personal_data_exports`, `account_deletion_requests` | `PRIVACY_CONSENTS_ENFORCE=false` |
| `impersonation` | Impersonación de soporte auditada | — | — | `IMPERSONATION_ENABLED=false` |
| `maintenance` | Modo mantenimiento (503) | — | — | — |
| `search` | Scout/Meilisearch y `/search` | — | — | `SCOUT_DRIVER=database` |
| `reverb` | WebSockets (autenticación de canales) | — | — | `BROADCAST_CONNECTION=null` |
| `seed-load` | Datos masivos reproducibles (`make seed-load`); ver `docs/seed-load.md` | `billing` | — | — (solo se ejecuta a mano) |
| `load-testing` | Escenarios k6 (humo, carga, estrés, pico, resistencia) y stack temporal (`make load-*`); ver `docs/load-testing.md` | `seed-load` | — | — (solo se ejecuta a mano) |
| `metrics` | `GET /metrics` en formato Prometheus (peticiones, latencia, colas, jobs fallidos, BD); ver `docs/metrics.md` | — | — | `METRICS_ENABLED=false` (por defecto) |
| `frontend-demo` | Cliente web de pruebas (Vite + React + TypeScript, tipos de `docs/openapi.json`) y recorridos Playwright; ver `docs/frontend-demo.md` | — | — | Se quita borrando `frontend-demo/` (sin código PHP) |

Las dependencias duras son `docs-assistant → llm`, `seed-load → billing` y `load-testing → seed-load`:
`module:remove` se niega a quitar un módulo mientras quede otro que dependa de él. El resto son independientes;
los puntos de contacto se resuelven con puntos de extensión (abajo).

## Qué hace `module:remove`

1. Comprueba que ningún módulo **presente** depende del que quitas (y que no es núcleo).
2. **Borra** los archivos del manifiesto (`files`: app, config, rutas, tests, doc, vistas, factories, Filament…).
3. Quita de los archivos compartidos las líneas con **marcadores** `module:<nombre>` (bindings en providers,
   `require` de rutas, schedule de `routes/console.php`, permisos de `PermissionSeeder`/`RoleSeeder`, menú,
   `.env.example`, `docker-compose.yml`, `phpunit.xml`, `AGENTS.md`, tests compartidos) y los `use` de las clases borradas.
4. Crea una migración **nueva** `*_drop_<modulo>_module_tables.php` que elimina sus tablas (`DROP TABLE IF EXISTS … CASCADE`).
5. Ejecuta Pint sobre lo editado, `composer dump-autoload`, `route:list` (la app arranca) y `openapi:export`
   (el contrato `docs/openapi.json` cambia al quitar rutas).
6. Avisa de los paquetes de composer que ya no usa nadie (`composer remove …`; no se ejecuta solo).

Es **idempotente** (un módulo ya quitado no hace nada) y `--dry-run` no toca nada.

### Migraciones: las dos opciones

- **Por defecto (seguro, también para proyectos desplegados):** las migraciones que crearon las tablas **se
  conservan** (ya están aplicadas en otros entornos y migraciones posteriores del núcleo las tocan, p. ej.
  `tenant_id` y `jsonb`) y la migración de baja elimina las tablas. En una BD nueva se crean y se borran justo
  después: coste cero y ningún entorno diverge.
- **`--purge-migrations` (solo antes del primer despliegue):** borra también las migraciones del módulo y no crea
  la de baja, así la BD nueva nace sin esas tablas. Un manifiesto con `'purgeable' => false` no lo admite. Si el
  proyecto ya desplegó, no la uses (`docs/migrations.md`: migraciones inmutables).

La migración de baja es irreversible (`down()` vacío): recupera los datos de una copia de seguridad.

## Marcadores en archivos compartidos

Un archivo compartido (provider, rutas, seeders…) marca lo que pertenece a un módulo para que `module:remove`
lo pueda quitar sin conocer el archivo:

```php
$this->app->bind(FooInterface::class, Foo::class); // module:foo           ← línea suelta
// module:foo:start
...varias líneas...
// module:foo:end                                                           ← bloque
```

Valen `//`, `#`, `<!-- -->` y `{{-- --}}` (PHP, `.env.example`, YAML, Markdown, Blade). Un bloque sin cerrar
aborta la baja antes de tocar nada. `tests/Feature/Modulos/ModulosInventarioTest.php` comprueba que cada marcador
apunta a un módulo opcional real y que los bloques están balanceados.

## El manifiesto

```php
// modules/billing.php
return [
    'description' => '…',
    'core'        => false,          // true = núcleo (no se quita)
    'depends'     => [],             // módulos de los que depende
    'files'       => ['app/Services/Billing', 'routes/api/billing.php', 'tests/Feature/Billing', …], // archivo, carpeta o patrón con *
    'migrations'  => ['database/migrations/…'],  // se conservan salvo --purge-migrations
    'tables'      => ['plans', …],   // las elimina la migración de baja
    'permissions' => ['billing.manage', …],      // informativo (se quitan con marcadores)
    'env'         => [],             // informativo
    'packages'    => [],             // composer: solo si NADIE más los usa
    'purgeable'   => true,           // false = no admite --purge-migrations
];
```

Regla del inventario (`ModulosInventarioTest`): **cada archivo** de `app/`, `routes/`, `config/`, `database/`,
`lang/`, `resources/`, `tests/`, `make/`, `stubs/`, `docker/` y `scripts/` pertenece a un módulo (núcleo u
opcional), ningún archivo es de dos opcionales y el núcleo no depende de un opcional. Un archivo nuevo sin dueño
hace fallar el test: añádelo al manifiesto de su módulo.

## Desacoplamientos (puntos de extensión)

El núcleo nunca importa un módulo opcional. Donde necesita algo de uno, define un contrato con valor por defecto
(null-object) que el módulo sustituye con su propio `bind` (el último binding gana; la línea del módulo lleva marcador):

| Contrato (núcleo) | Por defecto (núcleo) | Lo sustituye |
|---|---|---|
| `SubscriptionAccessInterface` (lo usa `ResolveTenant`) | `NullSubscriptionAccess`: acceso completo, sin avisos | `billing` → `SubscriptionAccess` (402 por impago) |
| `SubscriptionPlanSourceInterface` (lo usa `PlanLimits`) | `NullSubscriptionPlanSource`: sin suscripción → `tenants.plan` | `billing` → `SubscriptionPlanSource` |
| `SecurityAnalysisProviderInterface` (lo usa `SecurityAuditService`) | `NullSecurityAnalysisProvider`: escaneo sin análisis de IA | `llm` → `LlmSecurityAnalysisProvider` |

Además: `Support\Impersonation` (lector de claims del JWT) es núcleo y devuelve null sin el módulo; `User` conserva
las interfaces de Filament porque el 2FA del núcleo usa sus traits (el paquete `filament/filament` se queda);
`UserService` solo llama a `User::search()` si `scout.driver` es `meilisearch` (config que aporta `search`).

## Probar que se puede quitar cada módulo

En CI, el job `modules-removed` (`.github/workflows/ci.yml`) lo hace en tres escenarios (sin ningún opcional, solo sin
`billing`, solo sin `backoffice`): `module:remove`, `php artisan about`, migrar, `openapi:export` y la suite restante.

`tests/Unit/Modules/` prueba el motor (marcadores, baja, idempotencia) sobre un proyecto de juguete. La prueba de
verdad —quitar cada opcional **en una copia temporal** del repo y comprobar que arranca, que las rutas cargan y
que PHPStan, Pint y la suite restante pasan— se ejecuta así (nunca en el repo real):

```bash
rsync -a --exclude .git ./ /tmp/apygg-copia/ && cd /tmp/apygg-copia
make module-remove name=billing     # o: php artisan module:remove billing
php artisan route:list >/dev/null && make check && make test
```

## Añadir un módulo nuevo

`make module-new name=mi-modulo` genera el esqueleto de la checklist (manifiesto, interfaz + servicio + `bind`
con marcador, controller, Form Request, ruta, permiso en `PermissionSeeder::catalog()`, entrada en
`archServiciosConInterfaz()`, test con 401/403/200 y doc). Después completa a mano:

1. **Migración** nueva (nunca editar las publicadas): `jsonb`, índices, `down()`. `docs/migrations.md`. Anótala
   en `migrations` y sus tablas en `tables` del manifiesto.
2. **Modelo** (`App\Models\Model`; `BelongsToTenant` si guarda datos de una empresa) y, si procede, policy.
3. **Resource** de la respuesta pública y Form Requests tipados (`BaseFormRequest`); listados con `->paginate()`.
4. **Permisos** en `RoleSeeder` si algún rol los trae de serie (en su propia línea con marcador).
5. **Cola/eventos/schedule**: jobs que extienden `Job`; el `Schedule::command(...)` de `routes/console.php`
   envuelto en `// module:nombre:start` … `:end`.
6. **Config y `.env.example`** (bloque con marcadores) y `docs/upgrading.md` si rompe derivados.
7. **Tests**: camino feliz y denegado; **aislamiento A/B** si hay datos por empresa
   (`tests/Support/AssertsTenantIsolation.php`, `CoberturaDeAislamientoTest`); concurrencia si hay contadores.
8. **Doc** `docs/<modulo>.md` con su sección «Quitar el módulo».
9. `make check`, `make test`, `make openapi` y `make module-remove name=… DRY=1` (debe listar todo lo suyo).

Antes de empezar: ¿sirve a cualquier proyecto que parta del boilerplate? Si es lógica de un negocio concreto,
**no va aquí** (`AGENTS.md`). Si es dinero o un tercero, lee `docs/money.md` y `docs/integrations.md`.

Un módulo opcional debe cumplir: todo bajo carpetas propias, un único interruptor en `.env`, el núcleo no lo
importa (dependencia solo hacia el núcleo, o punto de extensión con valor nulo) y su sección «Quitar el módulo».

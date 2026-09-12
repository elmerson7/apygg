# APYGG — Guía para agentes y LLMs

> 📌 **Este archivo es el primero que deben leer las LLM / agentes** al trabajar
> en este repo, y es la **fuente de verdad**. Sus reglas prevalecen sobre
> cualquier contradicción en `docs/*.md`. Si una doc secundaria muestra un
> ejemplo que contradice lo aquí escrito, aplica este archivo y corrige la otra
> doc, no al revés.

APYGG es un **boilerplate de API**: el código que hay aquí es infraestructura
reutilizable, no un producto. Antes de añadir algo, pregúntate si sirve a
cualquier proyecto que parta de esta base. Si es lógica de un negocio concreto,
no va aquí.

## Documentación obligatoria — leer antes de implementar

1. `docs/CODING_STANDARDS.md` → patrones de código, naming y ejemplos
2. `docs/CONTROLLER_CONVENTIONS.md` → estructura de controllers
3. `docs/api-routes-conventions.md` → rutas y prefijos
4. `docs/api-response-structure.md` + `docs/error-handling.md` → contrato de respuestas

## Comandos — TODO va por Docker vía Make (NUNCA en el host)

- ❌ `php artisan <cmd>` directamente en el host (no hay PHP ni BD locales)
- ✅ `make art cmd="<comando>"` → ejecuta `php artisan` DENTRO del contenedor
- ✅ `make sh` → abre una shell en el contenedor para trabajar interactivamente
- Migraciones, tinker, colas, tests: **siempre** por `make`.
- `make help` lista todos los targets disponibles.

## Quality checks — OBLIGATORIO tras cualquier cambio PHP

- ✅ `make check` → `pint` (formato PSR-12) + `phpstan` (análisis estático)
- Equivale a `make pint` + `make phpstan` por separado.
- Si phpstan devuelve errores, corrígelos antes de dar el trabajo por terminado.
- `make install-hooks` instala los git hooks que ejecutan esto en cada commit.

## Stack

- Laravel 12 · PHP 8.5 · FrankenPHP/Octane (**no** nginx, **no** apache)
- PostgreSQL 18 · Redis 7 · Docker Compose
- JWT + Sanctum · Spatie Permission · Horizon · Reverb · Scout/Meilisearch
- Pest · Pint · PHPStan (Larastan)

Detalle completo en `TECH_STACK.md`.

## Convenciones de código

- `snake_case` → columnas de BD, atributos de modelos, claves de config y de
  respuestas JSON
- `camelCase` → métodos, variables, parámetros
- `PascalCase` → clases, namespaces, traits
- **IDs**: el `Model` base usa auto-incremental (`int`). Usa el trait `HasUuid`
  cuando el recurso deba exponer un identificador no adivinable. Ver
  `docs/id-strategy.md`.

## Arquitectura

```
app/
├── Console/Commands/ → comandos artisan
├── Contracts/        → interfaces de servicios y repositorios
├── Events/           → eventos del sistema
├── Http/             → controllers, middleware, requests, resources
├── Jobs/             → trabajos en cola
├── Logging/          → handlers y processors de Monolog
├── Models/           → modelos Eloquent (heredan de App\Models\Model)
├── Repositories/     → acceso a datos cuando la query es compleja
├── Services/         → lógica de aplicación
└── Traits/           → traits reutilizables
```

Los servicios se inyectan **por interfaz** (`app/Contracts/`); el binding está en
`ContractServiceProvider`.

## Rutas — NO existe el prefijo /api/

Las rutas cuelgan de la raíz. Cada módulo vive en `routes/api/<modulo>.php` y se
incluye con `require` desde `routes/api.php`.

| Ámbito | Prefijo | Ejemplo |
|---|---|---|
| Público | ninguno | `GET /health` |
| Recurso autenticado | el del módulo | `GET /users`, `POST /files` |
| Perfil del usuario en sesión | `/user` | `GET /user/profile` |
| Administración | `/admin` | `GET /admin/logs` |

Middleware con alias disponibles: `auth:api`, `permission:`, `role:`,
`auth:api-key`, `api-key-scope:`, `ip.whitelist`, `feature:`, `auth.optional`.

## Autenticación

- **JWT** (`php-open-source-saver/jwt-auth`) → método principal de la API
- **Sanctum** → tokens alternativos
- **API Keys** (`auth:api-key` + `api-key-scope:`) → integraciones máquina a máquina
- Rutas protegidas con `auth:api`; autenticación opcional con `auth.optional`
  (deja el usuario en `$request->attributes->get('auth_user')`)

## Base de datos

- PostgreSQL exclusivamente: nada de sintaxis MySQL (`jsonb`, `ILIKE`, etc.)
- Migraciones en `database/migrations/`
- `make schema-dump` → exporta el DDL a `database/schema.sql`
- `make backup` / `make restore-backup` / `make restore-remote` → ver `docs/backups-system.md`

## Testing

- Pest sobre PHPUnit. Tests en `tests/Unit/` y `tests/Feature/`.
- **Antes de correr tests la primera vez**: `make testdb-migrate`. Los tests usan
  una BD aislada `<DB_DATABASE>_test` que nunca toca la de desarrollo
  (ver `docs/testing-database.md`).
- `make test` · `make test-unit` · `make test-feature` ·
  `make test-filter filter=NombreTest`
- Nunca `php artisan test` en el host.
- Si la BD de tests queda con drift tras añadir migraciones: `make testdb-migrate`.

## Respuestas API — usar los métodos del Controller base

- ✅ `sendSuccess($data, $message, $statusCode)` → 200, 201
- ✅ `sendError($message, $statusCode, $errors)` → 4xx, 5xx
- ✅ `sendPaginated($paginator)` → listados
- ❌ NUNCA `response()->json()` a mano en un controller: rompe el envelope
- `App\Helpers\ApiResponse` sí se usa en middlewares, handlers y en el propio
  Controller base — ahí es la capa correcta.

## Manejo de errores

- Confía en el handler global de `bootstrap/app.php` (produce RFC 7807).
- `try-catch` solo cuando haya lógica específica que aportar.
- Registra con `LogService` en operaciones críticas.
- ❌ No verifiques existencia a mano con `if (! $model)`: usa `findOrFail()` y
  deja que la excepción llegue al handler.

## Rendimiento — no paginar en memoria

- Listados **siempre** con `->paginate()` en SQL. Nunca `->get()` seguido de
  `forPage()` o `array_slice()`.
- Si un filtro u ordenamiento necesita cálculo en PHP, pagina primero en SQL y
  transforma **solo la página**.
- Objetivo: endpoints de listado < 500 ms con miles de filas.
- Eager loading obligatorio (`with`, `withCount`): ver la regla N+1 en
  `docs/CODING_STANDARDS.md`.

## Cache

- **Listados públicos**: se pueden cachear en Redis con TTL corto.
- **Endpoints de admin**: no cachear datos transaccionales; optimizar con
  paginación SQL y eliminación de N+1.
- Todo endpoint que modifique algo visible en un listado público cacheado debe
  invalidar esa clave.
- Ver `docs/cache-service-usage.md`, `docs/cache-invalidation.md` y
  `docs/cache-warming.md`.

## Logging

- `LogService` para logs de aplicación; canales `activity`, `security`, `auth`
  organizados por fecha.
- Los warnings de deprecación de `vendor/` se filtran automáticamente
  (`app/Logging/FiltersDeprecationWarnings.php`, configurable en
  `config/logging.php` → `deprecation_filter`).

## Al añadir algo al boilerplate

1. ¿Sirve a cualquier proyecto que parta de esta base? Si no, no va aquí.
2. ¿Está documentado en `docs/`?
3. ¿Tiene test?
4. ¿Pasa `make check`?
5. ¿Se configura por `config/` + `.env` en lugar de estar hardcodeado?

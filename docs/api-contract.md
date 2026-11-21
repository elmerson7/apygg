# Contrato de la API (OpenAPI) y tipos para los fronts

La API publica su contrato como un documento **OpenAPI 3.1** generado por Scramble a partir del código
(rutas, Form Requests, API Resources). Está versionado en `docs/openapi.json` y es la fuente de:

1. la UI viva en `/docs/api` (Scramble),
2. los **tests de contrato** (`tests/Contract`), que validan respuestas reales contra él,
3. los **tipos TypeScript** de los fronts (Next.js, Expo...).

## Flujo al cambiar la API

```
cambias rutas / requests / resources  ->  make openapi  ->  make test-contract  ->  make types (en el front)
```

| Paso | Qué hace |
|------|----------|
| `make openapi` | Escribe `docs/openapi.json` (JSON estable: los diffs de git se leen). Hay que commitearlo con el cambio. |
| `make openapi-check` | Falla si el archivo versionado está desactualizado. Lo mismo lo hace un test de la suite por defecto (`OpenApiDocumentoTest`), así que **olvidar `make openapi` rompe la CI**. |
| `make test-contract` | Valida respuestas reales (health, auth, usuarios, settings, webhooks, errores 401/403/404/422) contra el documento. |
| `make types` | Genera `docs/api-types.d.ts` con `openapi-typescript` (versión fija en `OPENAPI_TS_VERSION`, Node efímero en Docker). |

Si una respuesta no cumple el contrato hay dos causas: el código se salió de lo documentado (arréglalo) o
el contrato quedó viejo (ajusta controller/Resource y regenera). Nunca edites `openapi.json` a mano.

> **Ojo:** Scramble infiere los tipos de los modelos leyendo la base de datos. Ejecuta `make openapi` con la BD migrada (lo normal dentro del contenedor `app`); con una BD vacía el spec pierde los tipos de los modelos y el test de frescura falla.

## Prefijo de versión y cambios que rompen

- `docs/openapi.json` se genera siempre SIN el prefijo `API_PREFIX` en los paths (los tipos de los fronts no cambian). Con
  prefijo, `servers[].url` termina en `/v1`; las rutas que nunca lo llevan (salud, webhooks) declaran su `servers` raíz. La
  suite funciona con `API_PREFIX=v1` (en CI, job `test-prefix`): `OpenApiDocumentoTest` compara todo salvo `servers`.
- `make openapi-breaking [BASE=<ref>]` (y el job `openapi-breaking` de CI) corre `oasdiff breaking` entre el contrato de la
  base y el actual y falla si rompe a las apps publicadas. Política, escapes y detalle: `docs/api-routes-conventions.md`
  («Versiones y apps móviles»).

## Qué describe el documento (y cómo)

- **Servidor**: `scramble.export_server_url` (`OPENAPI_SERVER_URL`, por defecto `http://localhost:8000`). Es fijo
  a propósito: no usa `APP_URL`, que cambia entre dev/CI y ensuciaría el diff. Los clientes reciben su base URL por configuración.
- **Rutas incluidas**: solo las de controllers de `App\Http\Controllers` (`App\OpenApi\ApiDocs::includesRoute`).
  Quedan fuera sin listas que mantener: backoffice (Filament), Livewire, Horizon, Telescope, closures y `test/*`.
- **Seguridad** (por operación, según el middleware de la ruta): `bearerAuth` (JWT) para `auth:api`,
  `apiKeyAuth` (`X-API-Key`) para `auth:api-key`; las rutas públicas llevan `security: []`.
- **Éxito**: `{ success: true, message, data }`. Un `sendSuccess(null)` omite `data`.
- **Listados**: `{ success, data: [...], meta, links }` con `PaginationMeta`/`PaginationLinks` y los parámetros
  `page` y `per_page`. Los ítems salen del `$resource` que declara el controller (Scramble no puede inferir el
  interior de `sendPaginated`).
- **Errores**: toda respuesta 4xx lleva el esquema `ApiError` con `code` y `request_id` (`ErrorCode` lista los
  códigos del boilerplate). Se documentan 401/403/404/422/429 por operación según middleware, parámetros de ruta y Form Request.

Todo esto lo hacen extensiones de Scramble (`app/OpenApi/Extensions`), no anotaciones en cada controller.

### Limitaciones conocidas

- Rutas con cuerpo que no sea el envelope (health) se anotan con `#[Response(...)]` en el controller.
- Un `sendPaginated($x, resource: false)` o un controller sin `$resource` documenta los ítems como `object` libre.
- Si el controller manda `data` con una forma que Scramble no infiere, anótalo con `#[Response]` o un PHPDoc `@return`.
- El `code` de error es un `string` abierto (los proyectos añaden los suyos); `ErrorCode` es el enum de los del boilerplate.
- Las 404 de **ruta** inexistente (sin operación) no se pueden validar contra una operación; sí las de recurso.

## Consumir el contrato desde un front (Next.js / Expo)

`docs/api-types.d.ts` **no se versiona** en este repo (≈220 KB que cambian con cada ruta, y la versión del
generador lo altera): cada front lo genera desde `docs/openapi.json`, que sí es la fuente versionada.

```jsonc
// package.json del front
"scripts": {
  "api:types": "openapi-typescript https://raw.githubusercontent.com/<org>/<api>/main/docs/openapi.json -o src/api/schema.d.ts"
}
```

(o apunta a una copia local/submódulo del repo de la API). Con `openapi-fetch` el cliente queda tipado extremo a extremo:

```ts
import createClient from "openapi-fetch";
import type { paths } from "./schema";

const api = createClient<paths>({ baseUrl: process.env.EXPO_PUBLIC_API_URL });

const { data, error } = await api.GET("/users/{id}", { params: { path: { id: "1" } } });
if (error) {
  // error es ApiError: ramifica por `code`, no por `message`
  if (error.code === "token_expired") { /* refrescar */ }
}
```

Para tipos sueltos: `components["schemas"]["UserResource"]`. Regenera los tipos cuando cambie `docs/openapi.json`
(un `git diff` del archivo dice qué cambió en la API).

## Paginación

### Por página (la de todos los listados actuales)

`GET /recurso?page=1&per_page=15` → `meta`:

```json
{ "current_page": 1, "per_page": 15, "total": 9, "last_page": 1, "from": 1, "to": 9 }
```

`links` trae `first`, `last`, `prev`, `next` (URLs; `null` si no hay). `per_page` se acota a **1..100**
(`RespondsWithApi::perPage()`); excepciones por compatibilidad: `/users` y `/permissions` aceptan hasta 500.
Un `per_page` fuera de rango no da error: se ajusta. Cuesta un `COUNT(*)` por página.

### Por cursor (opt-in, para listados grandes o de continuo)

`sendCursorPaginated(Model::query()->orderBy('id')->cursorPaginate($this->perPage($request)))` en el controller:

```json
{
  "success": true,
  "data": [ ... ],
  "meta": { "per_page": 15, "has_more": true, "next_cursor": "eyJpZCI6...", "prev_cursor": null },
  "links": { "next": "...?cursor=eyJpZCI6...", "prev": null }
}
```

El cliente reenvía `meta.next_cursor` en `?cursor=`; los cursores son opacos. No hay `total` ni `last_page` (no se
calculan: es lo que hace barato el listado) y las páginas no saltan ni repiten filas si entran registros nuevos.
Exige un orden estable y único (`orderBy('id')` o `orderBy('created_at')->orderBy('id')`). **No cambia ningún endpoint existente**:
cada proyecto lo activa donde lo necesite; el documento OpenAPI lo detecta solo (`CursorPaginationMeta`, parámetro `cursor`).

Cuándo usar cada una: paginación por página para listados de administración (se necesita «ir a la página N» y el total);
cursor para feeds, logs o tablas que crecen sin parar.

## Validador de contrato

`tests/Support/OpenApiValidator` usa `opis/json-schema` (JSON Schema 2020-12, el dialecto de OpenAPI 3.1).
`league/openapi-psr7-validator` se descartó: se apoya en cebe/php-openapi y valida con la semántica de OpenAPI 3.0
(`nullable`), así que no entiende `type: ["string","null"]`, `const` ni `examples` que emite Scramble 3.1.
En un test: `$this->assertMatchesOpenApi($response)` (toma método y plantilla de ruta de la última petición).

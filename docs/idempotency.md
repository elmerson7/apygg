# Idempotencia HTTP (`Idempotency-Key`)

Evita que un reintento (red caída, doble clic, cliente que reenvía) cree dos veces un recurso, cobre dos
veces o envíe dos correos. Sigue el estándar de facto (Stripe / IETF `Idempotency-Key`).

Código: `App\Http\Middleware\EnsureIdempotency` (alias `idempotent`), `App\Contracts\IdempotencyInterface`
→ `App\Services\IdempotencyService`, tabla `idempotency_keys`, `config/idempotency.php`,
comando `idempotency:clean`.

## Uso en rutas

```php
Route::middleware(['auth:api', 'tenant'])->group(function () {
    Route::post('/webhooks', ...)->middleware('idempotent');           // opcional: sin cabecera, funciona como siempre
    Route::post('/pagos', ...)->middleware('idempotent:required');     // obligatoria: sin cabecera, 400
});
```

Va **después** de `auth:api` y `tenant` (necesita saber quién actúa y en qué tenant). Solo vigila los
métodos de `idempotency.methods` (`POST` y `PATCH`); el resto pasa de largo. Aplicado hoy a
`POST /webhooks` y `POST /tenant/invitations` (opcional: los clientes actuales no cambian).

## Semántica

El cliente envía `Idempotency-Key: <clave>` (ASCII imprimible sin espacios, máx. 255; un UUID sirve).

| Situación | Respuesta |
|---|---|
| Primera vez | Se ejecuta; la respuesta se guarda |
| Misma clave + mismo método, ruta y cuerpo (canónico) | La respuesta guardada, mismo status y cuerpo, con `Idempotent-Replayed: true`; el controller no se ejecuta |
| Misma clave + otro método, ruta, query o cuerpo | 422 `idempotency_key_mismatch` |
| Misma clave mientras la primera sigue en curso | 409 `idempotency_key_in_progress` (o espera hasta `wait_ms` y devuelve la guardada) |
| Falta la cabecera en `idempotent:required`, o clave inválida | 400 `idempotency_key_required` |
| Respuesta 5xx, 408, 425, 429 o excepción | No se guarda y se libera la clave: el reintento se ejecuta |
| Respuesta 4xx | Se guarda (`store_client_errors`); tras corregir el cuerpo hay que usar **otra clave** |
| Respuesta mayor que `max_response_bytes`, o no textual (descarga) | Se ejecuta pero no se guarda: no hay repetición |

«Cuerpo canónico»: las claves de los objetos JSON se ordenan, así que `{"a":1,"b":2}` y `{"b":2,"a":1}`
son lo mismo; se incluyen la query y el hash de los archivos subidos.

## Aislamiento

La clave es única por **(tenant, actor, clave)**. Actor = `user:{id}`, `api_key:{id}` o `ip:{ip}`
(sin sesión). Dos usuarios, una API key y su usuario, o dos tenants pueden usar el mismo texto de
clave sin verse; nadie puede leer la respuesta guardada de otro.

## Concurrencia

La reserva es atómica antes de ejecutar el controller: `INSERT ... ON CONFLICT DO NOTHING` contra el
índice único `(COALESCE(tenant_id,0), actor, key)`. Solo quien inserta ejecuta. Si el proceso muere, la
reserva caduca a los `lock_seconds` y otra petición la toma con un `UPDATE ... WHERE expires_at <= now()`
(solo una gana). `complete`/`release` exigen el `token` de la reserva, así que un proceso lento cuya
reserva ya fue tomada no pisa al nuevo. `lock_seconds` debe superar la petición más lenta de las rutas
protegidas. Pruebas con procesos reales en `tests/Concurrency/IdempotenciaConcurrenteTest.php`.

## Qué se guarda

Estado, status, cabeceras de la respuesta (sin las de `sensitive_headers`: `Set-Cookie`,
`Authorization`, `X-Request-ID`...) y el cuerpo **cifrado** con `APP_KEY` (puede llevar secretos, p. ej. el
secreto de un webhook recién creado). El cuerpo de la petición no se guarda, solo su hash. Con `APP_KEY`
rotada una respuesta guardada no se puede descifrar: se trata como «en curso» hasta que caduque.

## Caducidad y limpieza

`ttl_hours` (24 h por defecto) para las respuestas; `lock_seconds` para las reservas. `idempotency:clean`
(`--dry-run`) borra lo caducado y corre cada hora en el scheduler.

## Configuración (`.env`)

`IDEMPOTENCY_ENABLED`, `IDEMPOTENCY_TTL_HOURS`, `IDEMPOTENCY_LOCK_SECONDS`, `IDEMPOTENCY_WAIT_MS`,
`IDEMPOTENCY_STORE_CLIENT_ERRORS`, `IDEMPOTENCY_MAX_RESPONSE_BYTES`; el resto en `config/idempotency.php`.

## Límites conocidos

- No sustituye a la idempotencia de dominio (índices únicos, `claim()` atómico): es la red del borde.
- Si el controller tiene efectos externos y la petición muere a medias, la clave queda reservada hasta
  `lock_seconds`; después se reejecuta (diseña esos efectos para ser idempotentes, ver `docs/integrations.md` §6).
- `wait_ms` > 0 bloquea el worker de Octane mientras espera: mantenlo pequeño.

# Integraciones externas (con versión simulada)

**Este es el patrón a seguir para cualquier servicio de terceros que añada un proyecto:
pasarelas de pago, WhatsApp, SMS, push, facturación electrónica, etc.** Hoy el repo lo aplica en
DNS (webhooks), verificación de firmas, correo y LLM; las pasarelas aún no existen, pero se
construirán con esta misma forma.

La idea: el resto del código habla con **una interfaz**; detrás hay una implementación real y
una falsa y determinista. Los tests, el desarrollo local y el CI **nunca** llaman a la red.

## 1. Las piezas

| Pieza | Dónde | Ejemplo real |
|---|---|---|
| Interfaz | `app/Contracts/` (el ArchTest exige que sea `interface`) | `HostResolverInterface`, `WebhookSignatureVerifierInterface` |
| Implementación real | `app/Services/...` | `Services\Webhooks\DnsHostResolver`, `Services\Mail\SvixSignatureVerifier` |
| Implementación falsa determinista | `tests/Support/` | `Tests\Support\FakeHostResolver` |
| Binding | `ContractServiceProvider` | `singleton(HostResolverInterface::class, DnsHostResolver::class)` |
| Elección por entorno | `config/*.php` + `.env` | `LLM_DEFAULT_PROVIDER`, `MAIL_MAILER` |
| Registro en el ArchTest | `archServiciosConInterfaz()` | `DnsHostResolver => HostResolverInterface` |

La interfaz describe **lo que necesita la app**, no el SDK del proveedor:

```php
interface HostResolverInterface
{
    /** @return list<string> IPs (v4 y v6) del host; vacío si no resuelve */
    public function resolve(string $host): array;
}
```

Una pasarela nueva sería igual de pequeña: `PaymentGatewayInterface::charge(...)` devolviendo un
objeto propio (no el del SDK) y lanzando excepciones propias (ver punto 4).

## 2. La implementación falsa

Determinista = misma entrada, misma salida, sin red, sin reloj, sin aleatoriedad. Se sustituye
con el contenedor, **sin estado estático** (Octane, `docs/octane.md`):

```php
// tests/Support/FakeHostResolver.php: resuelve cada host con el closure indicado
$this->app->instance(HostResolverInterface::class, new FakeHostResolver(fn ($host) => ['93.184.216.34']));
```

`tests/TestCase.php` lo hace por defecto en `setUp()` con `fakeDns()`, así que ningún test
depende de DNS. Aplica lo mismo a cada integración: **el `TestCase` instala el fake por defecto**
y un test concreto puede cambiarlo (p. ej. para simular un rechazo o un timeout). Un fake debe
poder simular también los fallos (pago rechazado, proveedor caído, respuesta lenta).

Otras formas de «versión simulada» que ya existen:

- LLM: `NullProvider` (`app/Services/LLM/Providers/`) se usa cuando no hay URL o API key: devuelve
  una respuesta «skipped» sin red, y el sistema degrada limpio en lugar de fallar.
- Correo: `MAIL_MAILER` cae a `log` fuera de producción (`config/mail.php`); en desarrollo,
  Mailpit; en tests, `Mail::fake()`.
- Almacenamiento: RustFS (S3 local) en el perfil `dev` (`docs/local-services.md`).
- Tests de contrato contra el servicio local real: `tests/Integration/` (`make test-integration`).

## 3. Elegir implementación por config/env

Nunca con `if (app()->environment(...))` repartidos por el código. Un único punto decide:

- Un `.env` / `config()` con el nombre del driver (`LLM_DEFAULT_PROVIDER`), o
- el binding del provider según config: `bind(PaymentGatewayInterface::class, fn () => match (config('payments.driver')) { 'fake' => new FakePaymentGateway, default => new StripeGateway(...) })`
  (patrón a seguir; hoy no hay ningún binding del repo elegido así).

Las claves y URLs salen de `config/services.php` o del config del módulo, nunca de `env()` en el
servicio (el ArchTest lo prohíbe en `App\Services` y `Controllers`). Un servicio sin credenciales
se desactiva con claridad (como `NullProvider`), no falla en mitad de una petición.

## 4. Timeouts, errores tipados y reintentos

**Todo cliente HTTP lleva timeout explícito.** Sin él, una integración lenta bloquea un worker
de Octane o de Horizon. Ejemplos: `Http::timeout(...)` en `WebhookService::processDelivery`
(timeout propio del webhook, 30 s por defecto), `AbstractHttpProvider` del LLM
(`LLM_TIMEOUT`, 60 s), `StorageCheckCommand` (5 s). El timeout HTTP debe ser **menor** que el
`timeout` del job que lo ejecuta (`docs/queues-and-jobs.md`).

**Errores tipados.** El código de negocio no debe capturar `\Exception` ni `ConnectionException`
del cliente. La capa de integración traduce a una excepción propia con la información útil:

- `LlmProviderException` (mensaje + `statusCode`): `AbstractHttpProvider::send()` convierte
  `ConnectionException` (timeout, DNS, conexión) en ella, igual que un HTTP 5xx.
- No hay una excepción genérica de «servicio externo» (existió `ExternalServiceException` y se
  borró: ningún código la lanzaba). Cada integración define la suya, pequeña y tipada, como
  `LlmProviderException`; si debe llegar al cliente como error HTTP, que extienda
  `ApiException` y se registre en el handler de `bootstrap/app.php`.

**Cuándo reintentar:**

| Resultado | Acción |
|---|---|
| Timeout, error de conexión, HTTP 5xx, 429 | Reintentable: relanza la excepción en el job (backoff) o pasa al siguiente modelo/proveedor |
| HTTP 4xx (excepto 408/429) | **No reintentar**: es un error nuestro o del dato; falla ya y registra |
| Éxito con cuerpo inesperado | No reintentar; error tipado y log |
| Operación que cobra o envía algo irreversible | Reintentar **solo** con clave de idempotencia (punto 6) |

`LlmGateway` añade un circuit breaker por área: tras `LLM_CIRCUIT_FAILURES` fallos deja de
llamar un rato (`docs/llm.md`).

> **Observación:** `WebhookService::recordFailure()` trata igual un 4xx que un 5xx (todo se
> reintenta hasta `max_retries`). Para integraciones nuevas aplica la tabla de arriba.

## 5. Webhooks entrantes: firma + ventana + idempotencia

Referencia completa: `MailWebhookController` (`POST /webhooks/mail`, `routes/api/mail.php`).
La ruta es pública (la llama el proveedor) y se protege **solo** por firma, no por login.
Orden obligatorio:

1. **Secreto configurado.** Sin secreto → 500 y log; nunca aceptar «porque no hay secreto».
2. **Firma verificada sobre el cuerpo crudo**, con comparación de tiempo constante
   (`hash_equals`). Vía `WebhookSignatureVerifierInterface`, así se sustituye y se prueba.
   `SvixSignatureVerifier` firma `"{id}.{timestamp}.{cuerpo}"` con HMAC-SHA256 y acepta varias
   firmas (rotación de secreto). Firma inválida → 401.
3. **Ventana de tiempo.** `SvixSignatureVerifier::TOLERANCE_SECONDS = 300`: un timestamp fuera
   de ±5 minutos se rechaza, para que una petición capturada no se reproduzca indefinidamente.
   El timestamp forma parte de lo firmado.
4. **Idempotencia.** El proveedor reintenta; el mismo evento llegará varias veces. Se guarda el
   id del evento con índice **único** (`mail_events.provider_event_id`, `unique()`) y un
   duplicado responde 200 «ignorado». Responde 200 también en los casos «no accionables» para
   que el proveedor no reintente sin fin.
5. **Guardar y responder rápido.** Valida, guarda el evento y haz el trabajo pesado en un job.

> `MailWebhookController` no comprueba el duplicado antes: inserta dentro de una transacción
> (savepoint) y captura `UniqueConstraintViolationException` sobre `provider_event_id`, de modo
> que dos entregas simultáneas dan 200 y una sola fila (`tests/Concurrency/MailWebhookDuplicadoTest`).
> Copia ese patrón: insertar y dejar que la restricción única decida, nunca `exists()` + `create()`.

Pruébalo con los tres casos: firma válida, firma inválida (401) y timestamp fuera de ventana, más
el duplicado. Un verificador falso (`WebhookSignatureVerifierInterface`) permite probar el
controller sin calcular HMAC.

Los webhooks **salientes** del propio APYGG (firma `X-Webhook-Signature`, SSRF, reclamar
entrega): `docs/webhooks.md`.

## 6. Pagos y operaciones no repetibles (norma para módulos futuros)

Para pagos, envíos de SMS/WhatsApp o cualquier llamada con efecto externo:

- Genera una **clave de idempotencia** propia por operación (uuid guardado antes de llamar) y
  envíala al proveedor (`Idempotency-Key`). Un reintento reutiliza la misma clave.
- Guarda el estado local (`pending` → `succeeded`/`failed`) **antes** de llamar y reclámalo con
  un UPDATE atómico como `WebhookDelivery::claim()`.
- La verdad del estado la da el webhook del proveedor (punto 5), no solo la respuesta síncrona.
- Dinero: ver `docs/money.md`.

## Checklist para una integración nueva

1. Interfaz en `app/Contracts/` + implementación real + binding + línea en `archServiciosConInterfaz()`.
2. Fake determinista en `tests/Support/` e instalado por defecto en `TestCase::setUp()`.
3. Driver y credenciales en `config/` + `.env.example`; sin credenciales, se desactiva limpio.
4. Timeout explícito; errores traducidos a excepción propia; reintento según la tabla.
5. Si hay webhook entrante: firma, ventana, idempotencia por índice único.
6. Tests: camino feliz con el fake, fallo del proveedor, firma inválida/duplicado.
7. Documentación en `docs/` y variables en `docs/upgrading.md` si afecta a proyectos derivados.

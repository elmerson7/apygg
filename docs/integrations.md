# Integraciones externas (real y simulada)

**Este es el patrón (y la implementación genérica) para cualquier servicio de terceros que añada un
proyecto: pasarelas de pago, WhatsApp, SMS, push, facturación electrónica, etc.** Vive en
`app/Integrations/` + `config/integrations.php` + `IntegrationsServiceProvider`. El ejemplo completo
y reutilizable es **SMS** (`SmsSenderInterface`); los **webhooks entrantes** tienen un mecanismo
genérico (§7).

La idea: el resto del código habla con **una interfaz**; detrás hay una implementación real y
una falsa y determinista. Los tests, el desarrollo local y el CI **nunca** llaman a la red.

| Qué | Dónde |
|---|---|
| Errores tipados (`Transient`, `RateLimited`, `QuotaExceeded`, `Permanent`, `InvalidOutput`) | `app/Integrations/Exceptions/` |
| Reintento por tipo de error (backoff + jitter, respeta `retry_after`) | `App\Integrations\RetryPolicy` |
| Cliente HTTP con timeouts y traducción de errores | `App\Integrations\IntegrationHttp` |
| Fake con llamadas registradas y fallos inyectables | trait `App\Integrations\Concerns\RecordsCalls` |
| Elección real/fake por `<SERVICIO>_MODE` | `IntegrationsServiceProvider::bindByMode()` |
| Ejemplo SMS | `app/Integrations/Sms/` (`FakeSmsSender`, `LogSmsSender`, `HttpSmsSender`, `SmsChannel`), `SendSmsJob` |
| Webhooks entrantes | `InboundWebhookService`, `InboundWebhookController`, `ProcessInboundWebhookJob`, tabla `inbound_webhook_receipts` |

## 1. Las piezas

| Pieza | Dónde | Ejemplo real |
|---|---|---|
| Interfaz | `app/Contracts/` (el ArchTest exige que sea `interface`) | `HostResolverInterface`, `WebhookSignatureVerifierInterface` |
| Implementación real | `app/Services/...` | `Services\Webhooks\DnsHostResolver`, `Services\Mail\SvixSignatureVerifier` |
| Implementación falsa determinista | `app/Integrations/<Servicio>/` si debe poder usarse en local (`SMS_MODE=fake`); `tests/Support/` si solo es de test | `FakeSmsSender`, `Tests\Support\FakeHostResolver` |
| Binding | `ContractServiceProvider` (sin variantes) o `IntegrationsServiceProvider::bindByMode()` (real/fake) | `bindByMode(SmsSenderInterface::class, 'sms', [...])` |
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
`config('integrations.<servicio>.mode')` = `real|fake` (env `<SERVICIO>_MODE`; **por defecto `fake`**, para
que nada llame a un proveedor por accidente). `IntegrationsServiceProvider::bindByMode()` enlaza
la interfaz con la fábrica del modo elegido, en binding **`scoped`** (una instancia por petición/job, sin
estado entre peticiones en Octane); un modo desconocido falla al resolver, con un mensaje claro:

```php
$this->bindByMode(SmsSenderInterface::class, 'sms', [
    'fake' => fn () => new FakeSmsSender,
    'real' => fn () => match (config('integrations.sms.driver', 'http')) {
        'log' => new LogSmsSender,
        'http' => new HttpSmsSender([...(array) config('integrations.sms.http'), 'from' => config('integrations.sms.from')]),
    },
]);
```

Las claves y URLs salen de `config/integrations.php` (o `config/services.php`), nunca de `env()` en el
servicio (el ArchTest lo prohíbe en `App\Services` y `Controllers`). Un servicio sin credenciales
falla de forma clara y tipada (`PermanentIntegrationError`), no a mitad de una petición con un 500 opaco.

## 4. Timeouts, errores tipados y reintentos

**Todo cliente HTTP lleva timeout explícito.** Sin él, una integración lenta bloquea un worker
de Octane o de Horizon. `IntegrationHttp::client('<servicio>')` ya aplica `INTEGRATIONS_HTTP_TIMEOUT`
(10 s) y `INTEGRATIONS_HTTP_CONNECT_TIMEOUT` (3 s), o el `timeout` propio de `integrations.<servicio>.http`.
El timeout HTTP debe ser **menor** que el `timeout` del job que lo ejecuta (`docs/queues-and-jobs.md`).

**Errores tipados.** El código de negocio no debe capturar `\Exception` ni `ConnectionException`.
`IntegrationHttp::send()` traduce a uno de cinco tipos (todos extienden `IntegrationException`, que
expone `isRetryable()` y `service()`; nunca pongas secretos ni datos personales en el mensaje):

| Tipo | Cuándo | ¿Se reintenta? |
|---|---|---|
| `TransientIntegrationError` | timeout, conexión, HTTP 5xx, 408 | Sí |
| `RateLimitedError` | HTTP 429 (`retryAfter()` desde `Retry-After`) | Sí, esperando `retry_after` |
| `QuotaExceededError` | HTTP 402, sin saldo/cuota | No (avisa a un humano) |
| `PermanentIntegrationError` | otros 4xx, credenciales ausentes, dato inválido | No |
| `InvalidOutputError` | éxito con cuerpo inesperado | No |

**Reintento.** `RetryPolicy::fromConfig()->run(fn () => $sender->send($msg))` reintenta **solo** los
reintentables (hasta `INTEGRATIONS_RETRY_ATTEMPTS`, 3), con backoff exponencial y jitter, o `retry_after`
con tope de 30 s. Los demás errores y cualquier otra excepción suben sin reintentar. Es para llamadas
**síncronas**; dentro de un job, usa los reintentos de la cola (`release()` con el `retry_after`; ver
`SendSmsJob`): un permanente hace `fail()` sin gastar intentos.

Si el error debe llegar al cliente como HTTP, captúralo en el controller/servicio y lanza una
`ApiException` con `errorCode()` propio.

`LlmGateway` añade un circuit breaker por área (`docs/llm.md`) y `LlmProviderException` es previo a
este patrón.

> **Observación:** `WebhookService::recordFailure()` (webhooks salientes) trata igual un 4xx que un 5xx
> (todo se reintenta hasta `max_retries`). Para integraciones nuevas aplica la tabla de arriba.

## 5. Webhooks entrantes: firma + ventana + idempotencia

Ya no se escribe un controller por proveedor: **se registra el proveedor en config** y el mecanismo
genérico (§7) hace todo lo siguiente. Orden (siempre; `InboundWebhookService`):

1. **Proveedor conocido** (si no, 404 `webhook_provider_unknown`) y **secreto configurado** (si no, 500
   `webhook_not_configured`; nunca se acepta «porque no hay secreto»).
2. **Tamaño máximo** (413 `payload_too_large`).
3. **Firma sobre el cuerpo crudo**, comparación en tiempo constante (`hash_equals`), con **ventana de tiempo**
   anti-replay (por defecto ±300 s; el timestamp forma parte de lo firmado). 401 con `webhook_signature_missing`,
   `webhook_signature_invalid` o `webhook_timestamp_expired`.
4. **JSON válido** (400 `webhook_payload_malformed`) y **reglas** del proveedor (422 `validation_failed`).
5. **Idempotencia atómica**: el recibo se inserta con índice **único** `(provider, delivery_id)` y la
   restricción decide; un duplicado responde 200 `duplicate` sin reprocesar (dos entregas simultáneas dan una sola fila,
   `tests/Concurrency/MailWebhookDuplicadoTest`). Nunca `exists()` + `create()`.
6. **Guardar y responder rápido**: 200 tras guardar; el trabajo lo hace un job en cola.

## 6. Pagos y operaciones no repetibles (norma para módulos futuros)

Para pagos, envíos de SMS/WhatsApp o cualquier llamada con efecto externo:

- Genera una **clave de idempotencia** propia por operación (uuid guardado antes de llamar) y
  envíala al proveedor (`Idempotency-Key`). Un reintento reutiliza la misma clave.
- Para las **peticiones entrantes** de tus propios clientes (crear, cobrar, enviar) usa el middleware
  `idempotent` / `idempotent:required`: implementación y semántica en `docs/idempotency.md`.
- Guarda el estado local (`pending` → `succeeded`/`failed`) **antes** de llamar y reclámalo con
  un UPDATE atómico como `WebhookDelivery::claim()`.
- La verdad del estado la da el webhook del proveedor (punto 5), no solo la respuesta síncrona.
- Dinero: ver `docs/money.md`.

## 7. Webhooks entrantes genéricos

`POST /webhooks/inbound/{provider}` (pública; la credencial es la firma; `throttle:inbound-webhooks`, 120/min por
IP y proveedor, `INBOUND_WEBHOOK_RATE_LIMIT`). Flujo: verificar -> guardar en `inbound_webhook_receipts` -> 200 -> job
`ProcessInboundWebhookJob` -> handler del proveedor.

**Recibo** (`InboundWebhookReceipt`): `provider`, `delivery_id` (único por proveedor), `tenant_id` nullable, `payload`
jsonb, `headers` jsonb (solo las permitidas; **nunca** firma, token ni cookies), `status`
(`received` -> `processed` | `failed`), `attempts`, `processed_at`, `error`. No usa `BelongsToTenant` a propósito:
se crea en una ruta pública antes de saber el tenant; el job restaura el tenant del recibo (`docs/multi-tenancy.md`).

**Job**: reclama el intento con un UPDATE atómico (un recibo `processed` no se repite), corre en el tenant del recibo
y llama al handler. Si el handler lanza, el recibo queda `failed` (con clase y mensaje truncados en `error`) y la cola
reintenta con backoff (`tries` 5; 30 s, 2 min, 10 min, 30 min); al funcionar pasa a `processed` y limpia `error`. Una
`PermanentIntegrationError` deja `failed` sin reintentar. **El handler debe ser idempotente.**

**Mantenimiento**: `php artisan webhooks:inbound-sweep` (programado cada 10 min) reencola recibos `received` atascados
(p. ej. Redis caído al recibirlos: el webhook no se pierde) y borra los antiguos (`INBOUND_WEBHOOK_RETENTION_DAYS`, 30,
porque el payload puede llevar datos personales).

**Logs**: solo proveedor, id de entrega, IP y motivo. Nunca el secreto ni el payload.

### Registrar un proveedor

1. Secreto en `config/services.php` (+ `.env.example`): `'acme' => ['webhook_secret' => env('ACME_WEBHOOK_SECRET')]`.
2. Handler: clase que implementa `App\Contracts\InboundWebhookHandlerInterface` (puede inyectar interfaces por constructor):

```php
class AcmeEventsHandler implements InboundWebhookHandlerInterface
{
    public function __construct(private readonly PaymentStatusServiceInterface $payments) {}

    public function handle(InboundWebhookReceipt $receipt): void
    {
        $this->payments->applyProviderEvent($receipt->payload['type'], $receipt->payload['data']); // idempotente
    }
}
```

3. Entrada en `config/integrations.php` -> `inbound_webhooks.providers`:

```php
'acme' => [
    'secret_config' => 'services.acme.webhook_secret',
    'signature' => ['driver' => 'hmac', 'header' => 'X-Acme-Signature', 'timestamp_header' => 'X-Acme-Timestamp', 'prefix' => 'sha256=', 'tolerance' => 300],
    'delivery_id' => ['header' => 'X-Acme-Delivery'],   // o ['payload' => 'id']; null = hash del cuerpo
    'handler' => AcmeEventsHandler::class,
    'rules' => ['type' => 'required|string'],
    'store_headers' => ['x-acme-delivery'],
],
```

Firmas disponibles (`signature.driver`): `svix` (Resend y otros; reutiliza `SvixSignatureVerifier`), `hmac` (HMAC-SHA256
genérico: cabecera, timestamp opcional con ventana, plantilla de lo firmado `{timestamp}.{body}`, prefijo, `hex|base64`,
rotación con varios secretos separados por comas) o una clase propia que implemente `WebhookSignatureVerifierInterface`
(mejor `InspectsWebhookSignatureInterface`, que además explica el motivo del rechazo). Solo JSON.

**Multiempresa**: `tenant_resolver` (clase `InboundWebhookTenantResolverInterface`) decide el tenant a partir del payload
**ya verificado**; `tenant_required => true` rechaza (422 `tenant_invalid`) si no hay. Un tenant inexistente siempre se rechaza.

**Resend** usa este mecanismo: `POST /webhooks/mail` es un alias que se mantiene (la URL ya está dada de alta en su panel)
de `POST /webhooks/inbound/resend`; su handler es `ResendMailEventsHandler`.

### Probarlo

`tests/Feature/Webhooks/WebhooksEntrantesTest.php` es la plantilla: firma válida, inválida, caducada, duplicado, job
encolado y procesado, handler que falla y reintento, y `tests/Feature/Tenancy/Aislamiento/WebhooksEntrantesAislamientoTest.php`
para el tenant. Registra un handler de prueba con `app()->instance(MiHandler::class, $fake)` (`tests/Support/RecordingInboundHandler`).

## 8. Ejemplo completo: SMS

```php
// Enviar (síncrono, con reintento de errores reintentables):
RetryPolicy::fromConfig()->run(fn () => app(SmsSenderInterface::class)->send(new SmsMessage('+34600000001', 'Tu código: 123456')));

// En cola (idempotencyKey fijada al encolar, reutilizada en cada reintento):
SendSmsJob::dispatch('+34600000001', 'Hola', $uuid);

// Para avisar a usuarios por varios canales (incluido sms) usa NotificationServiceInterface::notify() (docs/notifications.md)

// Notification de Laravel con canal `sms`:
public function via(object $notifiable): array { return ['sms']; }
public function toSms(object $notifiable): string|SmsMessage { return 'Tu pedido salió'; }
// destino: User::routeNotificationForSms() (teléfono del perfil) o Notification::route('sms', '+34...')
```

Modos: `SMS_MODE=fake` (por defecto; registra llamadas, sin red), `SMS_MODE=real SMS_DRIVER=log` (solo registra, sin texto
ni número completo), `SMS_MODE=real SMS_DRIVER=http` (POST JSON configurable en `integrations.sms.http`: URL, token,
nombres de campos, ruta del id en la respuesta; manda `Idempotency-Key`). Para un proveedor concreto con otro formato,
escribe tu clase que implemente `SmsSenderInterface` y añádela al `match` del provider.

En tests: `$fake = app(SmsSenderInterface::class); $fake->failNext(TransientIntegrationError::class, times: 2);` y luego
`$fake->sent()` / `$fake->calls('send')`. El fake es determinista (`fake-sms-1`, `fake-sms-2`...), y la misma
`idempotencyKey` devuelve el mismo resultado.

### Añadir otra integración (p. ej. push o pagos)

1. Interfaz en `app/Contracts/` que describa lo que necesita la app (objetos propios de entrada/salida, no los del SDK).
2. `app/Integrations/<Servicio>/`: `Fake…` (usa `RecordsCalls`, implementa `serviceName()`), la real (usa `IntegrationHttp`) y,
   si hace falta, un `Log…`.
3. Bloque `<servicio>` en `config/integrations.php` con `mode` y `.env.example` con `<SERVICIO>_MODE=fake`.
4. `bindByMode(...)` en `IntegrationsServiceProvider::register()`.
5. Tests: fake (llamadas y fallos), cada código HTTP -> error tipado (`Http::fake`), selección por config, reintento solo en reintentables.
6. Si hay webhook de estado: proveedor entrante (§7).

## Checklist para una integración nueva

1. Interfaz en `app/Contracts/` + implementación real + binding (`bindByMode`; línea en `archServiciosConInterfaz()` si es un servicio de `App\Services`).
2. Fake determinista con `RecordsCalls` (por defecto `mode=fake`; los tests nunca llaman a la red).
3. `mode` y credenciales en `config/integrations.php` + `.env.example`; sin credenciales, falla tipado y claro.
4. Timeout explícito (`IntegrationHttp`); errores traducidos a los cinco tipos; reintento solo de los reintentables.
5. Si hay webhook entrante: proveedor registrado en `inbound_webhooks.providers` (§7): firma, ventana, idempotencia por índice único.
6. Tests: camino feliz con el fake, fallo del proveedor, firma inválida/duplicado.
7. Documentación en `docs/` y variables en `docs/upgrading.md` si afecta a proyectos derivados.

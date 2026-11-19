# Colas y jobs

Cola: Redis (`QUEUE_CONNECTION=redis`), procesada por **Horizon** (`php artisan horizon`,
servicio `horizon` de `docker-compose.yml`). Panel y detalle: `docs/horizon-reverb-telescope.md`.

## Colas con nombre

`config/queue.php` define una conexión Redis por cola; `config/horizon.php` define cuáles
consume el supervisor (`supervisor-1`, igual en todos los entornos):

| Cola | Uso | ¿La consume Horizon? |
|---|---|---|
| `high` | urgente | sí |
| `default` | lo normal (correo, notificaciones, bienvenida) | sí |
| `low` | lo no urgente | sí |
| `webhooks` | entregas de webhooks salientes (`WEBHOOK_QUEUE_NAME`) | sí (`supervisor-webhooks`) |

`supervisor-webhooks` (en `defaults` y en cada entorno de `config/horizon.php`) consume la cola
`webhooks`, aislada de `default` para que un destino lento no retrase el correo.
`tests/Unit/Queue/ColasYTimeoutsTest` recorre las colas que usa el código (`onQueue`,
`config('webhooks.queue.queue')`, `config('mail-system.queue')`) y falla si alguna no la consume
un supervisor en algún entorno.

Regla: **toda cola que uses debe estar en el `queue` del supervisor de Horizon**. Si creas una
cola nueva, añádela a `config/queue.php`, a `config/horizon.php` y documenta su nombre aquí.
El nombre sale de `config()`, no se escribe a mano en cada `dispatch` (ver
`config('mail-system.queue')`, `config('webhooks.queue.queue')`).

## La base `Job` (`app/Jobs/Job.php`)

Todos los jobs heredan de `App\Jobs\Job` (el ArchTest exige que implementen `ShouldQueue`).
`handle()` ya envuelve tu lógica con logs, trace id, `LogService::logError` (Sentry) y aviso
al admin tras el último intento. Tú solo escribes `process()`, que **recibe las dependencias
por interfaz** (inyección de método vía `app()->call()`):

```php
// Forma abreviada de app/Jobs/SendWebhookJob.php
class SendWebhookJob extends Job
{
    public function __construct(int|string $webhookId, int|string $deliveryId)
    {
        parent::__construct();   // genera el trace id
        // ...asigna las propiedades
    }

    public function process(WebhookServiceInterface $webhookService): void { /* ... */ }
}
```

- No declares `handle()`; no pidas dependencias en el constructor (se serializan).
- `failed()` y `notifyFailure()` no admiten inyección: resuelve con `app(Interfaz::class)`.
- Un job nuevo: `class MiJob extends Job`, constructor que llama a `parent::__construct()`.

## Reintentos, backoff y timeout

Valores por defecto de la base: `$tries = 3`, `$timeout = 60`, `$backoff = [1, 2, 4]` (segundos).
Sobrescríbelos en el job cuando el trabajo lo pida:

- `SendWebhookJob`: `$tries = 1` y `$backoff = []`; reintenta con **redespacho propio** y delay exponencial (ver abajo). `$timeout = WEBHOOK_JOB_TIMEOUT` (150 s).
- `RunSecurityScanJob`: `$timeout = SECURITY_SCAN_TIMEOUT` (600 s, trabajo largo).
- Base `Job`: `$timeout = JOB_TIMEOUT` (60 s), asignado en el constructor desde `config('queue.job_timeout')`.

Reglas:

- El `timeout` del job debe ser **menor** que `retry_after` de la conexión
  (`REDIS_QUEUE_RETRY_AFTER`, 660 en `config/queue.php`, mayor que el job más largo, 600 s;
  lo comprueba `ColasYTimeoutsTest`); si no, otro worker puede recoger el
  mismo job mientras se ejecuta. Si subes un timeout, sube también `retry_after` y el
  `timeout` del supervisor de Horizon.
- Lanza la excepción para que se reintente; captúrala solo si el fallo es definitivo y
  quieres cerrar el estado (como hace `failed()`).
- Un job que llama a un servicio externo usa un timeout HTTP **menor** que el del job
  (ver `docs/integrations.md`).

Decisión sobre webhooks: el timeout HTTP por webhook admite hasta `WEBHOOK_MAX_TIMEOUT` (120 s,
validación y recorte en el envío), el job se corta a `WEBHOOK_JOB_TIMEOUT` (150 s = máximo + 30 s de
margen para DNS/SSRF/BD) y `retry_after` (660 s) lo supera con holgura.

**Reintentos de `SendWebhookJob`: un solo mecanismo.** Laravel no reintenta el job (`tries = 1`);
el máximo es por webhook (`max_retries`) y se cuenta en `WebhookDelivery::attempts`, así que tras un
fallo reintentable el propio job se redespacha con `delay` exponencial (`webhooks.retry`). Un 4xx del
destino (salvo 408/429) es **fallo permanente**: la entrega queda `failed` y no se reencola; 5xx,
408, 429, timeout y errores de conexión sí se reintentan hasta `max_retries`. Cada intento empieza con
`WebhookDelivery::claim()`, así que un duplicado no reenvía.

## Jobs idempotentes

Un job puede ejecutarse **dos veces** (reintento, worker caído, reintento manual). Debe dar el
mismo resultado. Patrones del repo:

1. **Reclamar con un UPDATE atómico.** `WebhookDelivery::claim()` pasa la entrega a
   `processing` solo si estaba `pending`/`failed` (o `processing` desde hace más de 5 min) y
   devuelve `true` únicamente a quien actualizó la fila:

   ```php
   $claimed = static::query()->whereKey($this->getKey())
       ->where(fn ($q) => $q->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED]) /* ... */)
       ->update(['status' => self::STATUS_PROCESSING, 'updated_at' => now()]) === 1;
   ```

   `WebhookService::processDelivery()` empieza con `if (! $delivery->claim()) return;`.
2. **Comprobar el estado final antes de actuar:** `SendWebhookJob` sale si
   `$delivery->isSuccessful()`.
3. **Tolerar que el registro ya no exista:** `SendWelcomeEmailJob` registra un aviso y termina
   si el usuario fue borrado; no relanza.

No uses `count()+1` ni «leer, decidir, escribir» sin bloqueo para numeraciones o contadores.

## `afterCommit`

Si despachas dentro de una transacción, el worker puede recoger el job **antes** del commit y
no encontrar la fila. Despacha con `->afterCommit()`: ejemplo en `WebhookService::send()`
(`SendWebhookJob::dispatch(...)->afterCommit()`). Las conexiones de `config/queue.php` tienen
`after_commit => false`, así que **no es automático**: ponlo tú en cada despacho que ocurra
dentro de `DB::transaction()` (o tras crear la fila que el job va a leer).

## Qué va en la carga del job

- **Ids y datos mínimos**, no modelos enormes ni colecciones. `SerializesModels` ya reduce un
  modelo a su id, pero el job lo vuelve a leer: asume que puede no existir o haber cambiado.
  Los jobs del repo reciben `int|string $userId`, `$webhookId`, `$deliveryId`.
- **Nunca secretos** (contraseñas, tokens, secretos de webhook): la carga se guarda en Redis y
  en `failed_jobs`. Pasa el id y lee el secreto dentro del job.
- Ejemplo correcto: `SendPasswordResetLinkJob` recibe solo `($email, $resetUrl)`; el token de
  reseteo se genera dentro del job (`PasswordServiceInterface`), nunca viaja en la carga.

## Pruebas

```php
Queue::fake();
// ... acción ...
Queue::assertPushed(SendPasswordResetLinkJob::class, 2);   // tests/Feature/Auth/SesionesTest.php
Queue::assertNotPushed(SendPasswordResetLinkJob::class);
```

- `Queue::fake()` / `Bus::fake()` comprueban **que** se encola (y en qué cola:
  `Queue::assertPushedOn('webhooks', ...)`); el job real se prueba aparte llamándolo:
  `(new MiJob($id))->handle()` o `app()->call([$job, 'process'])`.
- Prueba la **idempotencia**: ejecuta el job dos veces y comprueba que el efecto ocurre una
  sola vez; para carreras reales, `tests/Concurrency/` (`docs/testing.md`).
- Prueba el camino de fallo: que `failed()` deja el registro en estado final.

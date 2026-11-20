# Webhooks salientes (y dónde están los entrantes)

> **Dos mecanismos distintos.** Este documento trata los webhooks **salientes**: APYGG llama a la URL que registra un usuario
> (`/webhooks`, firma `X-Webhook-Signature`, SSRF, reintentos). Los webhooks **entrantes** (un proveedor externo llama a APYGG:
> Resend, pasarelas de pago, WhatsApp...) son otro mecanismo, genérico y con su propia tabla de recibos
> (`inbound_webhook_receipts`), firma, ventana anti-replay e idempotencia: `POST /webhooks/inbound/{provider}`,
> en `docs/integrations.md` §7.

Un usuario registra una URL (`POST /webhooks`) y recibe POSTs firmados cuando ocurren eventos (`config/webhooks.php` -> `events`).

## Qué eventos recibe cada webhook

**Solo los de recursos de su dueño** (el usuario afectado por el evento). Un webhook nunca recibe logins, altas o cambios de rol de otros usuarios, aunque escuche `events = null` (todos). El ámbito se define en `WebhookListener::ownerIdFor()`. Con multiempresa activo, además, un webhook pertenece a un tenant y solo recibe eventos ocurridos dentro de él (el job lleva el tenant); ver `docs/multi-tenancy.md`.

## SSRF

`WebhookUrlGuard` se aplica al crear/actualizar (regla `SafeWebhookUrl`) y de nuevo antes de cada envío:

- Solo `https` (`WEBHOOKS_ALLOW_HTTP=true` permite `http`, solo desarrollo); sin credenciales en la URL.
- Se resuelve el DNS y se rechaza si **alguna** IP es privada, loopback, link-local (metadata 169.254.169.254), CGNAT, multicast, reservada o IPv6 local.
- Al enviar se fija la IP validada (anti DNS-rebinding) y no se siguen redirecciones (un 3xx cuenta como fallo).
- Hosts internos de confianza en local: `WEBHOOKS_ALLOWED_HOSTS=host.docker.internal`. Vacío en producción.
- La respuesta del destino se recorta a `webhooks.max_response_body` (1000) y solo la ve el dueño (`GET /webhooks/{id}/deliveries`).

## Firma

Cabeceras de cada petición:

- `X-Webhook-Id`, `X-Webhook-Timestamp` (segundos Unix) y `X-Webhook-Signature`.
- Firma: `sha256=` + HMAC-SHA256(secreto, `"{timestamp}.{cuerpo crudo}"`). Durante la rotación del secreto la cabecera lleva dos firmas separadas por espacio (nuevo y anterior).
- El cuerpo `event.id` identifica la entrega: úsalo para deduplicar.

Ejemplo de verificación (PHP):

```php
$body = file_get_contents('php://input');            // cuerpo CRUDO
$ts = (int) $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'];
$esperada = 'sha256='.hash_hmac('sha256', "$ts.$body", $secreto);
$ok = abs(time() - $ts) <= 300
    && collect(explode(' ', $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']))
        ->contains(fn ($f) => hash_equals($esperada, $f));
```

El secreto se devuelve **una sola vez**, al crear el webhook, y al rotarlo (`POST /webhooks/{id}/rotate-secret`).

## Entrega e idempotencia

Cada entrega se "reclama" con un UPDATE atómico: un reintento automático y uno manual a la vez no envían dos veces, y una entrega ya exitosa no se reenvía. El envío se despacha tras el commit.

Reintentos: un 4xx del destino (salvo 408/429) es fallo permanente y no se reintenta; 5xx, 408, 429, timeout y errores de conexión se reintentan con backoff exponencial hasta `max_retries` (un solo mecanismo: el redespacho del `SendWebhookJob`, ver `docs/queues-and-jobs.md`). El timeout HTTP por webhook va de 5 a `WEBHOOK_MAX_TIMEOUT` (120 s). La cola `webhooks` la consume `supervisor-webhooks` de Horizon.

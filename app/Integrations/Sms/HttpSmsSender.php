<?php

namespace App\Integrations\Sms;

use App\Contracts\SmsSenderInterface;
use App\Integrations\Exceptions\InvalidOutputError;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\IntegrationHttp;

/**
 * Esqueleto REAL genérico: un POST JSON configurable (`config/integrations.php` -> `sms.http`),
 * sin atarse a un proveedor. Para uno concreto con otro formato, crea tu propia clase que
 * implemente `SmsSenderInterface` y enlázala en `IntegrationsServiceProvider` (docs/integrations.md).
 *
 * No reintenta: lanza errores tipados y quien llama decide (`RetryPolicy` en síncrono, la cola en `SendSmsJob`).
 * Manda `Idempotency-Key` para que un reintento no duplique el SMS.
 */
class HttpSmsSender implements SmsSenderInterface
{
    /**
     * @param  array<string, mixed>  $config  `integrations.sms.http` + `from`
     */
    public function __construct(private readonly array $config) {}

    public function send(SmsMessage $message): SmsResult
    {
        $url = (string) ($this->config['url'] ?? '');

        if ($url === '') {
            throw new PermanentIntegrationError('SMS_HTTP_URL no está configurada', 'sms');
        }

        $body = array_merge((array) ($this->config['extra'] ?? []), [
            (string) ($this->config['to_field'] ?? 'to') => $message->to,
            (string) ($this->config['body_field'] ?? 'body') => $message->body,
        ]);

        $from = $message->from ?? ($this->config['from'] ?? null);
        if ($from) {
            $body[(string) ($this->config['from_field'] ?? 'from')] = $from;
        }

        $request = IntegrationHttp::client('sms')->withHeaders(['Idempotency-Key' => $message->idempotencyKey]);

        if (! empty($this->config['token'])) {
            $request = $request->withHeaders([
                (string) ($this->config['auth_header'] ?? 'Authorization') => trim(($this->config['auth_scheme'] ?? 'Bearer').' '.$this->config['token']),
            ]);
        }

        $response = IntegrationHttp::send('sms', fn () => $request->post($url, $body));

        $id = data_get($response->json(), (string) ($this->config['id_path'] ?? 'id'));

        if (! is_scalar($id) || $id === '') {
            throw new InvalidOutputError('El proveedor de SMS no devolvió un id de mensaje', 'sms');
        }

        return new SmsResult((string) $id, 'queued', 'http');
    }
}

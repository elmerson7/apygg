<?php

namespace App\Jobs;

use App\Contracts\SmsSenderInterface;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\RateLimitedError;
use App\Integrations\Sms\SmsMessage;

/**
 * Envía un SMS en cola. La `idempotencyKey` se fija al encolar y se reutiliza en cada reintento,
 * así el proveedor no duplica el mensaje.
 *
 * Solo se reintentan los errores reintentables (Transient/RateLimited, respetando `retry_after`);
 * los permanentes (número inválido, 4xx, sin crédito) fallan ya, sin gastar intentos.
 */
class SendSmsJob extends Job
{
    public function __construct(
        protected string $to,
        protected string $body,
        protected string $idempotencyKey,
    ) {
        parent::__construct();
    }

    public function process(SmsSenderInterface $sender): void
    {
        try {
            $sender->send(new SmsMessage($this->to, $this->body, $this->idempotencyKey));
        } catch (IntegrationException $e) {
            if (! $e->isRetryable()) {
                $this->fail($e);

                return;
            }

            if ($this->attempts() >= $this->tries) {
                throw $e;
            }

            $this->release($e instanceof RateLimitedError && $e->retryAfter() !== null ? $e->retryAfter() : ($this->backoff[$this->attempts() - 1] ?? 4));
        }
    }
}

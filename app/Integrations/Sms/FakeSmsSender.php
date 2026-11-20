<?php

namespace App\Integrations\Sms;

use App\Contracts\SmsSenderInterface;
use App\Integrations\Concerns\RecordsCalls;

/**
 * SMS falso y determinista: no hace red, no usa reloj ni azar. Ids `fake-sms-1`, `fake-sms-2`...
 * por instancia (binding `scoped`). Una misma `idempotencyKey` devuelve el mismo resultado.
 */
class FakeSmsSender implements SmsSenderInterface
{
    use RecordsCalls;

    private int $counter = 0;

    /** @var array<string, SmsResult> */
    private array $byKey = [];

    public function send(SmsMessage $message): SmsResult
    {
        $this->record('send', $message);

        return $this->byKey[$message->idempotencyKey] ??= new SmsResult('fake-sms-'.++$this->counter, 'queued', 'fake');
    }

    /**
     * @return list<SmsMessage>
     */
    public function sent(): array
    {
        return array_map(fn (array $call) => $call['payload'], $this->calls('send'));
    }

    protected function serviceName(): string
    {
        return 'sms';
    }
}

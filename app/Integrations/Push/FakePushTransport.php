<?php

namespace App\Integrations\Push;

use App\Contracts\PushTransportInterface;
use App\Integrations\Concerns\RecordsCalls;

/**
 * Push falso y determinista: no hace red. Ids `fake-push-1`, `fake-push-2`... por instancia
 * (binding `scoped`). Útil en tests: `app(PushTransportInterface::class)->sent()`.
 */
class FakePushTransport implements PushTransportInterface
{
    use RecordsCalls;

    private int $counter = 0;

    public function send(PushMessage $message): PushResult
    {
        $this->record('send', $message);

        return new PushResult('fake-push-'.++$this->counter, 'fake');
    }

    /**
     * @return list<PushMessage>
     */
    public function sent(): array
    {
        return array_map(fn (array $call) => $call['payload'], $this->calls('send'));
    }

    protected function serviceName(): string
    {
        return 'push';
    }
}

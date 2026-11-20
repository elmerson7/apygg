<?php

namespace Tests\Support;

use App\Contracts\InboundWebhookHandlerInterface;
use App\Models\InboundWebhookReceipt;
use App\Support\Tenancy\TenantContext;
use Throwable;

/** Handler de prueba: guarda lo que recibe (y el tenant activo) y puede fallar a demanda. */
class RecordingInboundHandler implements InboundWebhookHandlerInterface
{
    /** @var list<array{id: int, tenant: int|null}> */
    public array $handled = [];

    public ?Throwable $failWith = null;

    public function handle(InboundWebhookReceipt $receipt): void
    {
        $this->handled[] = ['id' => $receipt->id, 'tenant' => app(TenantContext::class)->id()];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}

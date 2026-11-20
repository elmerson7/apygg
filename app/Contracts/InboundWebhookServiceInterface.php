<?php

namespace App\Contracts;

use Illuminate\Http\Request;

interface InboundWebhookServiceInterface
{
    /**
     * Verifica, guarda y encola un webhook entrante.
     *
     * @return array{status: 'received'|'duplicate', receipt_id: int}
     */
    public function receive(string $provider, Request $request): array;

    /**
     * Mantenimiento: reencola recibos atascados y borra los procesados antiguos.
     *
     * @return array{redispatched: int, pruned: int} `redispatched` incluye los `failed` reencolados con backoff
     */
    public function sweep(int $stuckMinutes = 10): array;
}

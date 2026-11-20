<?php

namespace Tests\Support;

use App\Contracts\InboundWebhookTenantResolverInterface;
use Illuminate\Http\Request;

/** Resuelve el tenant desde `payload.tenant` (ya firmado); en producción suele ser una cuenta/cliente del proveedor. */
class FixedTenantResolver implements InboundWebhookTenantResolverInterface
{
    public function resolve(Request $request, array $payload): ?int
    {
        return isset($payload['tenant']) ? (int) $payload['tenant'] : null;
    }
}

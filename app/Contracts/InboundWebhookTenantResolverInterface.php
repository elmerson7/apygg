<?php

namespace App\Contracts;

use Illuminate\Http\Request;

/**
 * Decide a qué tenant pertenece un webhook entrante, a partir de la petición YA verificada
 * (nunca de datos sin firmar). Devuelve el id del tenant o null si no se puede determinar.
 */
interface InboundWebhookTenantResolverInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolve(Request $request, array $payload): ?int;
}

<?php

namespace App\Contracts;

use Illuminate\Http\Request;

/**
 * WebhookSignatureVerifierInterface
 *
 * Contrato del verificador de firma de webhooks entrantes.
 */
interface WebhookSignatureVerifierInterface
{
    public function verify(Request $request, string $secret): bool;
}

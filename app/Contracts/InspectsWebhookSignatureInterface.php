<?php

namespace App\Contracts;

use Illuminate\Http\Request;

/**
 * Verificador que además explica POR QUÉ rechaza (para devolver un `code` distinto al cliente
 * y poder diagnosticar relojes desfasados).
 */
interface InspectsWebhookSignatureInterface extends WebhookSignatureVerifierInterface
{
    public const MISSING = 'missing';

    public const EXPIRED = 'expired';

    public const INVALID = 'invalid';

    /**
     * @return string|null null si la firma es válida; si no, MISSING, EXPIRED o INVALID
     */
    public function inspect(Request $request, string $secret): ?string;
}

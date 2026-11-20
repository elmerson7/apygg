<?php

namespace App\Integrations\Webhooks;

use App\Contracts\InspectsWebhookSignatureInterface;
use Illuminate\Http\Request;

/**
 * Verifica la cabecera `Stripe-Signature: t=<unix>,v1=<hmac>[,v1=<hmac>][,v0=...]` (docs/billing.md §Stripe).
 *
 * Firma = HMAC-SHA256(secreto, "{t}.{cuerpo crudo}") en hex, comparada en tiempo constante con CADA `v1`.
 * El secreto es el `whsec_...` completo; varios separados por comas permiten rotarlo sin cortes (vale cualquiera).
 * La ventana anti-replay (`services.stripe.webhook_tolerance`, 300 s) solo se evalúa con la firma ya válida: un cuerpo
 * falsificado siempre es `invalid`, nunca revela si el reloj estaba bien. Los `v0` (esquema de prueba) se ignoran.
 */
class StripeSignatureVerifier implements InspectsWebhookSignatureInterface
{
    public function verify(Request $request, string $secret): bool
    {
        return $this->inspect($request, $secret) === null;
    }

    public function inspect(Request $request, string $secret): ?string
    {
        $header = (string) $request->header('Stripe-Signature', '');

        if ($header === '') {
            return self::MISSING;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return self::INVALID;
        }

        $signed = $timestamp.'.'.$request->getContent();
        $valid = false;

        foreach (array_filter(array_map('trim', explode(',', $secret))) as $key) {
            $expected = hash_hmac('sha256', $signed, $key);

            foreach ($signatures as $candidate) {
                // Sin cortocircuito: el tiempo no depende de qué secreto o firma acertó.
                $valid = hash_equals($expected, $candidate) || $valid;
            }
        }

        if (! $valid) {
            return self::INVALID;
        }

        return abs(time() - (int) $timestamp) > (int) config('services.stripe.webhook_tolerance', 300) ? self::EXPIRED : null;
    }
}

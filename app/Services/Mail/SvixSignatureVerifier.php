<?php

namespace App\Services\Mail;

use App\Contracts\InspectsWebhookSignatureInterface;
use Illuminate\Http\Request;

/**
 * SvixSignatureVerifier
 *
 * Verificación de la firma de webhooks con el esquema Svix, que usan Resend y
 * varios proveedores más. Se implementa aquí para no añadir una dependencia
 * por 40 líneas de HMAC.
 *
 * Esquema:
 *   - Cabeceras: `svix-id`, `svix-timestamp`, `svix-signature`
 *   - Contenido firmado: "{id}.{timestamp}.{body}"
 *   - Firma: base64(HMAC-SHA256(contenido, base64_decode(secreto sin 'whsec_')))
 *   - `svix-signature` trae una lista separada por espacios: "v1,<firma> v1,<firma>"
 *     (durante una rotación de secreto puede haber más de una).
 */
class SvixSignatureVerifier implements InspectsWebhookSignatureInterface
{
    /**
     * Ventana de tolerancia para el timestamp, en segundos. Evita que una
     * petición capturada pueda reproducirse indefinidamente.
     */
    private const TOLERANCE_SECONDS = 300;

    public function verify(Request $request, string $secret): bool
    {
        return $this->inspect($request, $secret) === null;
    }

    public function inspect(Request $request, string $secret): ?string
    {
        $id = $request->header('svix-id');
        $timestamp = $request->header('svix-timestamp');
        $signatureHeader = $request->header('svix-signature');

        if (! $id || ! $timestamp || ! $signatureHeader) {
            return self::MISSING;
        }

        if (! $this->timestampIsFresh($timestamp)) {
            return ctype_digit($timestamp) ? self::EXPIRED : self::INVALID;
        }

        $key = $this->decodeSecret($secret);

        if ($key === null) {
            return self::INVALID;
        }

        $signedContent = "{$id}.{$timestamp}.{$request->getContent()}";
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $key, true));

        foreach (explode(' ', $signatureHeader) as $versionedSignature) {
            $parts = explode(',', $versionedSignature, 2);

            if (count($parts) !== 2 || $parts[0] !== 'v1') {
                continue;
            }

            if (hash_equals($expected, $parts[1])) {
                return null;
            }
        }

        return self::INVALID;
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= self::TOLERANCE_SECONDS;
    }

    /**
     * El secreto viene como `whsec_<base64>`; la clave HMAC es el base64 decodificado.
     */
    private function decodeSecret(string $secret): ?string
    {
        $raw = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $decoded = base64_decode($raw, true);

        return $decoded === false ? null : $decoded;
    }
}

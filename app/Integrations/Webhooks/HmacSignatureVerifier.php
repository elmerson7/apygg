<?php

namespace App\Integrations\Webhooks;

use App\Contracts\InspectsWebhookSignatureInterface;
use Illuminate\Http\Request;

/**
 * Firma HMAC-SHA256 genérica sobre el cuerpo CRUDO, con ventana anti-replay opcional.
 *
 * Opciones (`signature` del proveedor en `config/integrations.php`):
 *   header            cabecera con la firma (por defecto `X-Signature`)
 *   timestamp_header  cabecera con el timestamp UNIX; si se define, entra en la firma y se
 *                     exige que esté a ±`tolerance` segundos (por defecto 300)
 *   signed_payload    plantilla de lo firmado, `{timestamp}` y `{body}` (por defecto `{timestamp}.{body}`
 *                     con timestamp y solo `{body}` sin él)
 *   prefix            prefijo de la cabecera (`sha256=`); puede venir varias veces separado por comas/espacios
 *   encoding          `hex` (por defecto) o `base64`
 *
 * El secreto admite varios valores separados por comas (rotación). Comparación con `hash_equals`.
 */
class HmacSignatureVerifier implements InspectsWebhookSignatureInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(private readonly array $options = []) {}

    public function verify(Request $request, string $secret): bool
    {
        return $this->inspect($request, $secret) === null;
    }

    public function inspect(Request $request, string $secret): ?string
    {
        $header = (string) ($this->options['header'] ?? 'X-Signature');
        $timestampHeader = $this->options['timestamp_header'] ?? null;
        $received = (string) $request->header($header, '');

        if ($received === '') {
            return self::MISSING;
        }

        $timestamp = '';
        if ($timestampHeader) {
            $timestamp = (string) $request->header((string) $timestampHeader, '');

            if ($timestamp === '') {
                return self::MISSING;
            }

            if (! ctype_digit($timestamp)) {
                return self::INVALID;
            }

            if (abs(time() - (int) $timestamp) > (int) ($this->options['tolerance'] ?? 300)) {
                return self::EXPIRED;
            }
        }

        $template = (string) ($this->options['signed_payload'] ?? ($timestampHeader ? '{timestamp}.{body}' : '{body}'));
        $signed = strtr($template, ['{timestamp}' => $timestamp, '{body}' => $request->getContent()]);
        $prefix = (string) ($this->options['prefix'] ?? '');
        $base64 = ($this->options['encoding'] ?? 'hex') === 'base64';

        $candidates = array_filter(preg_split('/[\s,]+/', $received) ?: []);

        foreach (array_filter(array_map('trim', explode(',', $secret))) as $key) {
            $raw = hash_hmac('sha256', $signed, $key, true);
            $expected = $prefix.($base64 ? base64_encode($raw) : bin2hex($raw));

            foreach ($candidates as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return null;
                }
            }
        }

        return self::INVALID;
    }
}

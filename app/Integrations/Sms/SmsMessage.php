<?php

namespace App\Integrations\Sms;

use App\Integrations\Exceptions\PermanentIntegrationError;

/**
 * Un SMS a enviar. `idempotencyKey` identifica la OPERACIÓN (no el intento): un reintento
 * reutiliza la misma clave y el proveedor no lo envía dos veces (docs/integrations.md §6).
 */
final readonly class SmsMessage
{
    public string $to;

    public string $idempotencyKey;

    /**
     * @param  array<string, scalar|null>  $metadata  datos propios para trazar (sin datos personales)
     */
    public function __construct(
        string $to,
        public string $body,
        ?string $idempotencyKey = null,
        public ?string $from = null,
        public array $metadata = [],
    ) {
        $normalized = preg_replace('/[\s().-]+/', '', $to) ?? '';

        if (! preg_match('/^\+[1-9]\d{6,14}$/', $normalized)) {
            throw new PermanentIntegrationError('Número de teléfono no válido: se espera formato E.164 (+34600000000)', 'sms');
        }

        if (trim($body) === '') {
            throw new PermanentIntegrationError('El SMS no tiene texto', 'sms');
        }

        $this->to = $normalized;
        $this->idempotencyKey = $idempotencyKey ?? bin2hex(random_bytes(16));
    }

    /** Número enmascarado para logs: `+3460***000`. */
    public function maskedTo(): string
    {
        return self::mask($this->to);
    }

    public static function mask(string $phone): string
    {
        $len = strlen($phone);

        return $len <= 6 ? str_repeat('*', $len) : substr($phone, 0, 3).str_repeat('*', $len - 6).substr($phone, -3);
    }
}

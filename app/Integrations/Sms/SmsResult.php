<?php

namespace App\Integrations\Sms;

/** Resultado de un envío: id del proveedor (para cruzar con su webhook de estado) y estado. */
final readonly class SmsResult
{
    public function __construct(
        public string $id,
        public string $status = 'queued',
        public string $provider = 'unknown',
    ) {}
}

<?php

namespace App\Integrations\Push;

/** Resultado de un envío push: id del mensaje en el proveedor. */
final readonly class PushResult
{
    public function __construct(
        public string $id,
        public string $provider = 'unknown',
    ) {}
}

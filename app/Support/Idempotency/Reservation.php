<?php

namespace App\Support\Idempotency;

/**
 * Resultado de reservar una clave de idempotencia (ver IdempotencyInterface::begin()).
 */
final class Reservation
{
    public const ACQUIRED = 'acquired';

    public const REPLAY = 'replay';

    public const MISMATCH = 'mismatch';

    public const IN_PROGRESS = 'in_progress';

    /**
     * @param  self::*  $outcome
     * @param  array<string, list<string|null>>  $headers
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?int $id = null,
        public readonly ?string $token = null,
        public readonly ?int $status = null,
        public readonly array $headers = [],
        public readonly ?string $body = null,
    ) {}
}

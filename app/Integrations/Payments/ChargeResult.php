<?php

namespace App\Integrations\Payments;

/**
 * Lo que la pasarela sabe de un cobro. `succeeded = false` sin más es un rechazo (`failureCode`). Estados intermedios:
 * `requiresAction` (3DS/SCA: el front confirma con `clientSecret`) y `processing` (la pasarela aún no decide; llega por webhook).
 * Los campos opcionales permiten cuadrar el cobro con lo local (importe, moneda, cliente, clave de idempotencia).
 */
final readonly class ChargeResult
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public bool $succeeded,
        public string $paymentId,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public bool $requiresAction = false,
        public bool $processing = false,
        public ?string $clientSecret = null,
        public ?string $customerId = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
    ) {}
}

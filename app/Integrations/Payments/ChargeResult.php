<?php

namespace App\Integrations\Payments;

final readonly class ChargeResult
{
    public function __construct(
        public bool $succeeded,
        public string $paymentId,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}
}

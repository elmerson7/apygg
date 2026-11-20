<?php

namespace App\Integrations\Payments;

use App\Support\Money\Money;

final readonly class ChargeRequest
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $customerId,
        public string $paymentMethodId,
        public Money $amount,
        public string $idempotencyKey,
        public string $description,
        public array $metadata = [],
    ) {}
}

<?php

namespace App\Integrations\Payments;

use App\Support\Money\Money;

final readonly class RefundRequest
{
    public function __construct(
        public string $paymentId,
        public Money $amount,
        public string $idempotencyKey,
        public ?string $reason = null,
    ) {}
}

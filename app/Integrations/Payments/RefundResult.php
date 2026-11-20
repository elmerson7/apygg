<?php

namespace App\Integrations\Payments;

final readonly class RefundResult
{
    public function __construct(public string $refundId, public bool $succeeded) {}
}

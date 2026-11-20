<?php

namespace App\Integrations\Payments;

final readonly class GatewayPaymentMethod
{
    public function __construct(public string $id, public ?string $brand = null, public ?string $last4 = null) {}
}

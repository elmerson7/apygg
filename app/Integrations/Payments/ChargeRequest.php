<?php

namespace App\Integrations\Payments;

use App\Support\Money\Money;

final readonly class ChargeRequest
{
    /**
     * @param  array<string, string>  $metadata
     * @param  bool  $customerPresent  el cliente está delante (alta, cambio de plan, «pagar ahora»): la pasarela puede pedirle 3DS
     *                                 (`ChargeResult::$requiresAction`). `false` = cobro automático sin cliente (renovación).
     */
    public function __construct(
        public string $customerId,
        public string $paymentMethodId,
        public Money $amount,
        public string $idempotencyKey,
        public string $description,
        public array $metadata = [],
        public bool $customerPresent = false,
    ) {}
}

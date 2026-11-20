<?php

namespace App\Http\Resources\Billing;

use App\Http\Resources\BaseResource;
use App\Models\BillingAccount;
use Illuminate\Http\Request;

/**
 * Método de pago por defecto: solo marca y últimos 4. Ni ids de la pasarela ni tokens.
 *
 * @mixin BillingAccount
 */
class BillingAccountResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'has_payment_method' => $a->hasPaymentMethod(),
            'brand' => $a->payment_method_brand,
            'last4' => $a->payment_method_last4,
        ];
    }
}

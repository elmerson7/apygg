<?php

namespace App\Models;

use App\Traits\BelongsToTenant;

/**
 * Cliente y método de pago del tenant en la pasarela. Nunca guarda datos de tarjeta.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $gateway
 * @property string|null $gateway_customer_id
 * @property string|null $payment_method_id
 * @property string|null $payment_method_brand
 * @property string|null $payment_method_last4
 * @property string|null $billing_email
 */
class BillingAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = ['gateway', 'gateway_customer_id', 'payment_method_id', 'payment_method_brand', 'payment_method_last4', 'billing_email'];

    protected $hidden = ['gateway_customer_id', 'payment_method_id'];

    public function hasPaymentMethod(): bool
    {
        return $this->gateway_customer_id !== null && $this->payment_method_id !== null;
    }
}

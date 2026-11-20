<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PaymentStatus;
use App\Support\Money\Money;
use App\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intento de cobro por la pasarela (o pago manual) de una factura. La fila se crea ANTES de llamar a la
 * pasarela, con su `idempotency_key`; el reintento tras un corte reutiliza la misma fila y la misma clave.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $invoice_id
 * @property int $attempt
 * @property string $method
 * @property string|null $gateway
 * @property PaymentStatus $status
 * @property Money $amount
 * @property string $currency
 * @property string $idempotency_key
 * @property string|null $gateway_payment_id
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property string|null $reference
 * @property string|null $reason
 * @property int|null $recorded_by
 * @property int $tries
 * @property CarbonInterface|null $claimed_until
 * @property CarbonInterface|null $processed_at
 * @property CarbonInterface|null $refunded_at
 * @property string|null $refund_id
 */
class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'invoice_id', 'attempt', 'method', 'gateway', 'status', 'amount', 'currency', 'idempotency_key', 'gateway_payment_id',
        'failure_code', 'failure_message', 'reference', 'reason', 'recorded_by', 'tries', 'claimed_until', 'processed_at', 'refunded_at', 'refund_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => MoneyCast::class,
            'attempt' => 'integer',
            'tries' => 'integer',
            'claimed_until' => 'datetime',
            'processed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

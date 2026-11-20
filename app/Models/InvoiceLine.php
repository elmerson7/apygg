<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Exceptions\BillingException;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de factura. Solo se crea al emitir; después no se modifica ni se borra (la factura es inmutable).
 * No lleva `tenant_id`: hereda el aislamiento de su factura (que sí lo tiene) y se accede siempre por ella.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $type
 * @property string $description
 * @property int $quantity
 * @property Money $unit_amount
 * @property Money $amount
 * @property string $currency
 * @property CarbonInterface|null $period_start
 * @property CarbonInterface|null $period_end
 */
class InvoiceLine extends Model
{
    protected $fillable = ['invoice_id', 'type', 'description', 'quantity', 'unit_amount', 'amount', 'currency', 'period_start', 'period_end'];

    protected static function booted(): void
    {
        static::updating(fn () => throw BillingException::invoiceImmutable());
        static::deleting(fn () => throw BillingException::invoiceImmutable());
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => MoneyCast::class,
            'amount' => MoneyCast::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
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

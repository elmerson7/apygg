<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\InvoiceReason;
use App\Enums\InvoiceStatus;
use App\Exceptions\BillingException;
use App\Support\Money\Money;
use App\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Factura. INMUTABLE una vez emitida: solo cambian `status`, `paid_at` y `voided_at` (con transición válida
 * de `InvoiceStatus`); no se borra. Los importes se guardan calculados y no se recalculan al mostrarlos.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $subscription_id
 * @property int|null $plan_price_id
 * @property string|null $number
 * @property string $number_scope
 * @property InvoiceStatus $status
 * @property InvoiceReason $billing_reason
 * @property string $currency
 * @property Money $subtotal
 * @property Money $tax
 * @property Money $total
 * @property string $tax_rate
 * @property string|null $tax_label
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property CarbonInterface|null $issued_at
 * @property CarbonInterface|null $due_at
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $voided_at
 * @property array<string, mixed>|null $metadata
 * @property-read Collection<int, InvoiceLine> $lines
 */
class Invoice extends Model
{
    use BelongsToTenant;

    /** Lo único que puede cambiar tras emitirla. */
    private const MUTABLE_AFTER_ISSUE = ['status', 'paid_at', 'voided_at', 'updated_at'];

    protected $fillable = [
        'subscription_id', 'plan_price_id', 'number', 'number_scope', 'status', 'billing_reason', 'currency', 'subtotal', 'tax', 'total',
        'tax_rate', 'tax_label', 'period_start', 'period_end', 'issued_at', 'due_at', 'paid_at', 'voided_at', 'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            $original = InvoiceStatus::from((string) $invoice->getRawOriginal('status'));

            if ($original === InvoiceStatus::Draft) {
                return;
            }

            if (array_diff(array_keys($invoice->getDirty()), self::MUTABLE_AFTER_ISSUE) !== []) {
                throw BillingException::invoiceImmutable();
            }

            if ($invoice->isDirty('status') && ! $original->canTransitionTo($invoice->status)) {
                throw BillingException::invoiceImmutable();
            }
        });

        static::deleting(fn () => throw BillingException::invoiceImmutable());
    }

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'billing_reason' => InvoiceReason::class,
            'subtotal' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'total' => MoneyCast::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('attempt');
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}

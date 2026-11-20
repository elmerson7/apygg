<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Carbon\CarbonInterface;

/**
 * Algo que la pasarela dice y no cuadra con lo local (docs/billing.md §Conciliación). Se revisa a mano en
 * `GET /admin/billing/discrepancies`. Único por (gateway, reference, kind): repetir el webhook o el comando no lo duplica.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $payment_id
 * @property int|null $invoice_id
 * @property string $kind
 * @property string $gateway
 * @property string $reference
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property array<string, mixed>|null $details
 * @property string $status
 * @property CarbonInterface $detected_at
 * @property CarbonInterface|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 */
class PaymentDiscrepancy extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = ['payment_id', 'invoice_id', 'kind', 'gateway', 'reference', 'amount_minor', 'currency', 'details', 'status', 'detected_at', 'resolved_at', 'resolved_by', 'resolution_note'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'amount_minor' => 'integer',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}

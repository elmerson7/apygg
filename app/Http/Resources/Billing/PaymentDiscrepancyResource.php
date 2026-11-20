<?php

namespace App\Http\Resources\Billing;

use App\Http\Resources\BaseResource;
use App\Models\PaymentDiscrepancy;
use Illuminate\Http\Request;

/**
 * @mixin PaymentDiscrepancy
 */
class PaymentDiscrepancyResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'id' => $d->id,
            'tenant_id' => $d->tenant_id,
            'payment_id' => $d->payment_id,
            'invoice_id' => $d->invoice_id,
            'kind' => $d->kind,
            'gateway' => $d->gateway,
            'reference' => $d->reference,
            'amount_minor' => $d->amount_minor,
            'currency' => $d->currency,
            'details' => $d->details,
            'status' => $d->status,
            'detected_at' => $d->detected_at->toIso8601String(),
            'resolved_at' => $d->resolved_at?->toIso8601String(),
            'resolution_note' => $d->resolution_note,
        ];
    }
}

<?php

namespace App\Http\Resources\Billing;

use App\Http\Resources\BaseResource;
use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * Factura con importes enteros en unidad menor + `currency`. Los ids de la pasarela no salen nunca.
 *
 * @mixin Invoice
 */
class InvoiceResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status->value,
            'billing_reason' => $i->billing_reason->value,
            'currency' => $i->currency,
            'subtotal_minor' => $i->subtotal->amountMinor,
            'tax_minor' => $i->tax->amountMinor,
            'total_minor' => $i->total->amountMinor,
            'tax_rate' => $i->tax_rate,
            'tax_label' => $i->tax_label,
            'period_start' => $i->period_start->toIso8601String(),
            'period_end' => $i->period_end->toIso8601String(),
            'issued_at' => $i->issued_at?->toIso8601String(),
            'due_at' => $i->due_at?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'voided_at' => $i->voided_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $i->lines->map(fn ($l) => [
                'type' => $l->type,
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unit_amount_minor' => $l->unit_amount->amountMinor,
                'amount_minor' => $l->amount->amountMinor,
            ])->values()->all()),
            'payments' => $this->whenLoaded('payments', fn () => $i->payments->map(fn ($p) => [
                'attempt' => $p->attempt,
                'method' => $p->method,
                'status' => $p->status->value,
                'failure_code' => $p->failure_code,
                'reference' => $p->reference,
                'processed_at' => $p->processed_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}

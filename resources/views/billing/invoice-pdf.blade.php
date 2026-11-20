{{-- Factura de suscripción en PDF (docs/billing.md §Facturas). Sin recursos remotos (dompdf no los carga). --}}
@php($primary = $issuer['colors']['primary'] ?? '#111827')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>Factura {{ $invoice->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
        h1 { font-size: 20px; margin: 0 0 4px; color: {{ $primary }}; }
        .muted { color: #6b7280; }
        .header, .parties { width: 100%; margin-bottom: 18px; }
        .header td, .parties td { vertical-align: top; }
        .right { text-align: right; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.lines th { text-align: left; border-bottom: 2px solid {{ $primary }}; padding: 6px 4px; font-size: 10px; text-transform: uppercase; }
        table.lines td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; }
        table.totals { width: 45%; margin-left: 55%; margin-top: 12px; border-collapse: collapse; }
        table.totals td { padding: 4px; }
        table.totals tr.total td { border-top: 2px solid {{ $primary }}; font-weight: bold; font-size: 13px; }
        .status { display: inline-block; padding: 2px 8px; border: 1px solid {{ $primary }}; color: {{ $primary }}; text-transform: uppercase; font-size: 10px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ $issuer['name'] }}</h1>
                @if ($issuer['legal_address'])<div class="muted">{{ $issuer['legal_address'] }}</div>@endif
                @if ($issuer['support_email'])<div class="muted">{{ $issuer['support_email'] }}</div>@endif
            </td>
            <td class="right">
                <h1>Factura {{ $invoice->number }}</h1>
                <div class="status">{{ $invoice->status->value }}</div>
                <div class="muted">Emitida: {{ $invoice->issued_at?->format('Y-m-d') }}</div>
                @if ($invoice->paid_at)<div class="muted">Pagada: {{ $invoice->paid_at->format('Y-m-d') }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <div class="muted">Cliente</div>
                <strong>{{ $customer->name }}</strong>
            </td>
            <td class="right">
                <div class="muted">Periodo</div>
                {{ $invoice->period_start->format('Y-m-d') }} – {{ $invoice->period_end->format('Y-m-d') }}
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="right">Cant.</th>
                <th class="right">Precio</th>
                <th class="right">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="right">{{ $line->quantity }}</td>
                    <td class="right">{{ $money($line->unit_amount) }}</td>
                    <td class="right">{{ $money($line->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="right">{{ $money($invoice->subtotal) }}</td></tr>
        <tr><td>{{ $invoice->tax_label ?: 'Impuestos' }} ({{ $invoice->tax_rate }}%)</td><td class="right">{{ $money($invoice->tax) }}</td></tr>
        <tr class="total"><td>Total {{ $invoice->currency }}</td><td class="right">{{ $money($invoice->total) }}</td></tr>
    </table>
</body>
</html>

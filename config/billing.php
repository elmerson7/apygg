<?php

/*
|--------------------------------------------------------------------------
| Facturación y suscripciones (docs/billing.md)
|--------------------------------------------------------------------------
|
| Catálogo declarativo: `php artisan billing:sync-plans` lo copia a las tablas `plans`/`plan_prices`
| (idempotente). Los LÍMITES de cada plan viven en `config/plans.php` bajo el mismo código.
| Importes en unidad menor de la moneda (2900 = 29,00 USD), nunca decimales (docs/money.md).
|
*/
return [
    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'USD'),

    'invoice' => [
        // Secuencia de `config/sequences.php`. `global` (por defecto): una serie para toda la plataforma, porque quien
        // emite las facturas es la plataforma (serializa la emisión entre tenants: la transacción de la factura es corta).
        // `tenant`: una numeración por empresa cliente. Se fija ANTES de la primera factura (luego no se puede cambiar).
        // Nombre propio (no `invoice`): un proyecto derivado que emita SUS facturas con la secuencia `invoice` (ejemplo de
        // docs/sequences.md) no debe compartir contador con las facturas de la suscripción.
        'sequence' => 'billing_invoice',
        'sequence_scope' => env('BILLING_INVOICE_SEQUENCE_SCOPE', 'global'),
        'due_days' => 0,
    ],

    // Impuesto simple, sin lógica fiscal de país: un % sobre el subtotal, redondeado una vez (mitad hacia fuera de cero).
    'tax' => [
        'rate' => env('BILLING_TAX_RATE', '0'),
        'label' => env('BILLING_TAX_LABEL', 'Impuestos'),
    ],

    // Renovación fallida -> `past_due` y reintentos a N días del fallo anterior. Con [1,3,5]: fallo 1 -> reintento a +1 día,
    // fallo 2 -> +3, fallo 3 -> +5, fallo 4 -> se agota. `final_status`: estado al agotarse (canceled | expired).
    'dunning' => [
        'retry_days' => array_values(array_filter(array_map('intval', explode(',', (string) env('BILLING_DUNNING_RETRY_DAYS', '1,3,5'))))),
        'final_status' => env('BILLING_DUNNING_FINAL_STATUS', 'canceled'),
    ],

    // Una suscripción `incomplete` (primer pago sin cobrar) caduca a las N horas.
    'incomplete_expire_hours' => (int) env('BILLING_INCOMPLETE_EXPIRE_HOURS', 24),

    'payment' => [
        // Arrendamiento de un cobro en curso: otro proceso no lo repite mientras dure (y tras vencer lo retoma con LA MISMA clave).
        'lease_seconds' => 120,
        // Error transitorio de la pasarela: se reintenta con la misma clave tras este tiempo, hasta `max_transient_tries`.
        'transient_backoff_seconds' => 300,
        'max_transient_tries' => 5,
    ],

    // Tope de días del periodo gratis que concede un admin de plataforma.
    'free_period_max_days' => 365,

    // `false`: `billing:renew` no factura a tenants suspendidos o pendientes (se omiten y se registra en el log de seguridad).
    'renew_inactive_tenants' => (bool) env('BILLING_RENEW_INACTIVE_TENANTS', false),

    // Suscripciones procesadas por ejecución de `billing:renew`.
    'renew_batch' => 200,

    'plans' => [
        'free' => [
            'description' => 'Para empezar.',
            'public' => true,
            'trial_days' => 0,
            'sort' => 10,
            'prices' => ['USD' => ['month' => 0, 'year' => 0]],
        ],
        'pro' => [
            'description' => 'Para equipos en crecimiento.',
            'public' => true,
            'trial_days' => 14,
            'sort' => 20,
            'prices' => [
                'USD' => ['month' => 2900, 'year' => 29000],
                'EUR' => ['month' => 2700, 'year' => 27000],
            ],
        ],
        // Privado: no sale en GET /plans, pero se puede contratar conociendo el código (acuerdos a medida).
        'enterprise' => [
            'description' => 'A medida.',
            'public' => false,
            'trial_days' => 0,
            'sort' => 30,
            'prices' => ['USD' => ['month' => 99900, 'year' => 999000]],
        ],
    ],
];

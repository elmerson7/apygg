<?php

/*
|--------------------------------------------------------------------------
| Monedas admitidas y su exponente (ISO 4217) — docs/money.md
|--------------------------------------------------------------------------
|
| Código => decimales de la unidad menor (EUR 2, JPY 0, KWD 3). Una moneda que no esté aquí NO se
| acepta (`Money` lanza UnsupportedCurrency): añádela con su exponente correcto.
|
*/
return [
    'exponents' => [
        // 0 decimales
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        // 3 decimales
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        // 2 decimales
        'AED' => 2, 'ARS' => 2, 'AUD' => 2, 'BOB' => 2, 'BRL' => 2, 'CAD' => 2, 'CHF' => 2, 'CNY' => 2,
        'COP' => 2, 'CRC' => 2, 'CZK' => 2, 'DKK' => 2, 'DOP' => 2, 'EUR' => 2, 'GBP' => 2, 'GTQ' => 2,
        'HKD' => 2, 'HNL' => 2, 'HUF' => 2, 'IDR' => 2, 'ILS' => 2, 'INR' => 2, 'MXN' => 2, 'MYR' => 2,
        'NIO' => 2, 'NOK' => 2, 'NZD' => 2, 'PAB' => 2, 'PEN' => 2, 'PHP' => 2, 'PLN' => 2, 'RON' => 2,
        'RUB' => 2, 'SAR' => 2, 'SEK' => 2, 'SGD' => 2, 'THB' => 2, 'TRY' => 2, 'TWD' => 2, 'UAH' => 2,
        'USD' => 2, 'UYU' => 2, 'VES' => 2, 'ZAR' => 2,
    ],
];

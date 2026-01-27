<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'null'),
    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                // En Docker: usar nombre del servicio 'reverb' y puerto interno 8080
                // Para cliente externo: usar REVERB_HOST (localhost) y REVERB_PORT (8012)
                'host' => env('REVERB_HOST_INTERNAL', env('REVERB_HOST', 'localhost')),
                'port' => env('REVERB_PORT_INTERNAL', env('REVERB_PORT', 443)),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],
    ],
];

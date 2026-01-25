<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Este archivo contiene la configuración de Feature Flags del sistema.
    | Los Feature Flags permiten activar/desactivar funcionalidades sin
    | necesidad de cambiar código o hacer deploy.
    |
    | Estructura:
    | - enabled: boolean - Indica si la feature está activada
    | - description: string - Descripción de la feature
    |
    | Uso:
    |   if (Feature::enabled('feature_name')) {
    |       // código de la feature
    |   }
    |
    | Para activar/desactivar features, edita las variables de entorno
    | en el archivo .env correspondiente.
    |
    */

    'advanced_search' => [
        'enabled' => env('FEATURE_ADVANCED_SEARCH', false),
        'description' => 'Búsqueda avanzada con filtros múltiples y autocompletado',
    ],

    'export_users' => [
        'enabled' => env('FEATURE_EXPORT_USERS', false),
        'description' => 'Exportación de usuarios a Excel/CSV',
    ],

    'two_factor_auth' => [
        'enabled' => env('FEATURE_TWO_FACTOR_AUTH', false),
        'description' => 'Autenticación de dos factores (2FA)',
    ],

    'debug_endpoints' => [
        'enabled' => env('FEATURE_DEBUG_ENDPOINTS', false),
        'description' => 'Endpoints de debug (solo para desarrollo)',
    ],

    'beta_api_features' => [
        'enabled' => env('FEATURE_BETA_API_FEATURES', false),
        'description' => 'Funcionalidades beta de la API',
    ],

    'real_time_notifications' => [
        'enabled' => env('FEATURE_REAL_TIME_NOTIFICATIONS', false),
        'description' => 'Notificaciones en tiempo real via WebSockets',
    ],

    'advanced_logging' => [
        'enabled' => env('FEATURE_ADVANCED_LOGGING', true),
        'description' => 'Sistema de logging avanzado con auditoría',
    ],

    'rate_limiting_adaptive' => [
        'enabled' => env('FEATURE_RATE_LIMITING_ADAPTIVE', false),
        'description' => 'Rate limiting adaptativo basado en comportamiento',
    ],
];

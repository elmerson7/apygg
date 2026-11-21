<?php

use Laravel\Socialite\Two\GithubProvider;

/*
|--------------------------------------------------------------------------
| Login social (módulo opcional `social-login`, docs/social-login.md)
|--------------------------------------------------------------------------
|
| API-only: la app (móvil/SPA) hace el flujo OAuth2/OIDC con PKCE contra el proveedor y canjea el
| `id_token` (OIDC) o el `access_token` en `POST /auth/social/{provider}` por los JWT de la API.
| Apagado por defecto. Un proveedor sin `client_ids`/credenciales no se acepta (404).
|
| Tipos de proveedor:
|  - `oidc`: valida el `id_token` (firma contra el JWKS, `iss`, `aud`, `exp`, `nonce`). Para Google, Apple, Microsoft...
|  - `socialite`: el `access_token` se cambia por el perfil con una clase de Laravel Socialite (`socialite_class`),
|    previa comprobación de que el token es de NUESTRA aplicación (`check_token_url`, evita aceptar tokens de otra app).
|
*/

$lista = static fn (string $valor): array => array_values(array_filter(array_map('trim', explode(',', $valor))));

return [
    'enabled' => (bool) env('SOCIAL_LOGIN_ENABLED', false),

    // ¿Crear el usuario si no existe? Solo con email verificado por el proveedor.
    'auto_register' => (bool) env('SOCIAL_LOGIN_AUTO_REGISTER', false),

    // Exigir que la app envíe `nonce` (y que vaya dentro del id_token). Con `false` solo se compara si viene.
    'require_nonce' => (bool) env('SOCIAL_LOGIN_REQUIRE_NONCE', false),

    // Fallos de canje por IP en la ventana antes de responder 429.
    'max_failures_per_ip' => (int) env('SOCIAL_LOGIN_MAX_FAILURES_PER_IP', 10),
    'failure_window_minutes' => (int) env('SOCIAL_LOGIN_FAILURE_WINDOW_MINUTES', 15),

    // Margen de reloj (segundos) al validar `exp`/`iat` del id_token y minutos de caché del JWKS.
    'leeway_seconds' => (int) env('SOCIAL_LOGIN_LEEWAY', 60),
    'jwks_cache_minutes' => (int) env('SOCIAL_LOGIN_JWKS_CACHE_MINUTES', 60),

    'providers' => [
        'google' => [
            'type' => 'oidc',
            'client_ids' => $lista((string) env('SOCIAL_GOOGLE_CLIENT_IDS', '')),
            'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
        ],

        'github' => [
            'type' => 'socialite',
            'socialite_class' => GithubProvider::class,
            'client_id' => env('SOCIAL_GITHUB_CLIENT_ID'),
            'client_secret' => env('SOCIAL_GITHUB_CLIENT_SECRET'),
            // El email que devuelve Socialite para GitHub es el principal VERIFICADO.
            'trust_email' => true,
            // POST con `access_token` y Basic client_id:client_secret: 200 = el token es de esta app.
            'check_token_url' => 'https://api.github.com/applications/{client_id}/token',
        ],
    ],
];

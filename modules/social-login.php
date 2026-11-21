<?php

// Manifiesto del módulo «social-login» (docs/modules.md): login OAuth2/OIDC (Google, GitHub, cualquier proveedor de Socialite).
return [
    'description' => 'Login social para apps móviles/SPA: canje de id_token/access_token por los JWT de la API, con vinculación segura por email verificado.',
    'core' => false,
    'depends' => [],
    'files' => [
        'app/Contracts/SocialIdentityResolverInterface.php',
        'app/Contracts/SocialLoginServiceInterface.php',
        'app/Exceptions/SocialLoginException.php',
        'app/Http/Controllers/Auth/SocialLoginController.php',
        'app/Http/Requests/Auth/SocialLoginRequest.php',
        'app/Models/SocialAccount.php',
        'app/Services/Social',
        'app/Support/Social',
        'config/social-login.php',
        'database/migrations/2026_11_21_000301_create_social_accounts_table.php',
        'docs/social-login.md',
        'routes/api/social-login.php',
        'tests/Concurrency/SocialLoginConcurrenteTest.php',
        'tests/Feature/Auth/SocialLogin',
        'tests/Support/FakeSocialIdentityResolver.php',
    ],
    'migrations' => [
        'database/migrations/2026_11_21_000301_create_social_accounts_table.php',
    ],
    'tables' => [
        'social_accounts',
    ],
    'permissions' => [],
    'env' => [],
    'packages' => [
        'laravel/socialite',
    ],
];

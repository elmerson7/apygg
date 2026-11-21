<?php

// Manifiesto del módulo «otp-login» (docs/modules.md): login sin contraseña por código de un solo uso.
return [
    'description' => 'Login sin contraseña por código de un solo uso (correo y SMS): hasheado, con caducidad, intentos máximos y límites por destino e IP.',
    'core' => false,
    'depends' => [],
    'files' => [
        'app/Console/Commands/OtpLoginCleanCommand.php',
        'app/Contracts/OtpLoginServiceInterface.php',
        'app/Exceptions/OtpLoginException.php',
        'app/Http/Controllers/Auth/OtpLoginController.php',
        'app/Http/Requests/Auth/OtpRequestRequest.php',
        'app/Http/Requests/Auth/OtpVerifyRequest.php',
        'app/Models/LoginOtp.php',
        'app/Notifications/LoginCodeNotification.php',
        'app/Services/Otp',
        'config/otp-login.php',
        'database/migrations/2026_11_21_000302_create_login_otps_table.php',
        'docs/otp-login.md',
        'lang/en/otp-login.php',
        'lang/es/otp-login.php',
        'routes/api/otp-login.php',
        'tests/Concurrency/OtpLoginConcurrenteTest.php',
        'tests/Feature/Auth/OtpLogin',
    ],
    'migrations' => [
        'database/migrations/2026_11_21_000302_create_login_otps_table.php',
    ],
    'tables' => [
        'login_otps',
    ],
    'permissions' => [],
    'env' => [],
    'packages' => [],
];

<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],

        // API Keys (máquina a máquina): `auth:api-key` + `api-key-scope:<scope>`.
        // El driver se registra en AuthServiceProvider (Auth::viaRequest).
        'api-key' => [
            'driver' => 'api-key',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Rol por Defecto
    |--------------------------------------------------------------------------
    |
    | Rol que se asigna a un usuario nuevo cuando no se indica ninguno. Sin
    | esto, los usuarios se crean sin rol y por tanto sin ningún permiso, lo
    | que se manifiesta como 403 inexplicables en el primer uso.
    |
    | Déjalo vacío para no asignar ninguno.
    |
    */

    'default_role' => env('AUTH_DEFAULT_ROLE', 'user'),

    /*
    |--------------------------------------------------------------------------
    | Administrador inicial
    |--------------------------------------------------------------------------
    |
    | Lo crea AdminUserSeeder (php artisan db:seed) con el rol admin. Sin
    | ADMIN_EMAIL no se crea; sin ADMIN_PASSWORD en producción tampoco (nunca
    | hay una contraseña por defecto).
    |
    */

    'initial_admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'name' => env('ADMIN_NAME', 'Administrador'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bloqueo por intentos fallidos de login
    |--------------------------------------------------------------------------
    |
    | max_attempts: fallos permitidos por pareja identidad + IP.
    | max_attempts_per_identity: fallos permitidos contra una misma identidad
    | sumando todas las IP (impide esquivar el límite rotando de IP).
    |
    */

    'login' => [
        'max_attempts' => (int) env('AUTH_LOGIN_MAX_ATTEMPTS', 5),
        'max_attempts_per_identity' => (int) env('AUTH_LOGIN_MAX_ATTEMPTS_PER_IDENTITY', 10),
        'lockout_minutes' => (int) env('AUTH_LOGIN_LOCKOUT_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Orígenes permitidos para el enlace de recuperación de contraseña
    |--------------------------------------------------------------------------
    |
    | `reset_url` de POST /auth/forgot-password solo se acepta si su origen
    | (esquema://host[:puerto]) está en esta lista; si no se define, se usa
    | ALLOWED_ORIGINS (CORS). Sin ninguno, solo se admite el enlace por defecto.
    |
    */

    'password_reset_per_email_per_hour' => (int) env('AUTH_PASSWORD_RESET_PER_EMAIL_PER_HOUR', 3),

    'password_reset_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PASSWORD_RESET_ALLOWED_ORIGINS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sesiones de usuario, 2FA y verificación de correo (docs/sessions-and-2fa.md)
    |--------------------------------------------------------------------------
    */

    'sessions' => [
        // Segundos que se cachea "la sesión sigue activa" (la revocación lo olvida al instante).
        'cache_ttl' => (int) env('AUTH_SESSION_CACHE_TTL', 30),
        // Cada cuántos segundos como máximo se actualiza `last_used_at` de una sesión.
        'touch_interval' => (int) env('AUTH_SESSION_TOUCH_INTERVAL', 60),
        // true = los tokens sin `sid` (emitidos antes de las sesiones) dejan de valer. Actívalo
        // pasado `JWT_REFRESH_TTL` desde el despliegue (hasta entonces conviven por compatibilidad).
        'require_sid' => (bool) env('AUTH_REQUIRE_SESSION', false),
        // Segundos en que presentar el refresh INMEDIATAMENTE anterior (carrera legítima de dos pestañas o
        // peticiones) solo da 401 `refresh_superseded` sin revocar la sesión. Fuera de la gracia, o con un
        // refresh más antiguo, es reutilización: se revoca la sesión.
        'refresh_reuse_grace_seconds' => (int) env('AUTH_REFRESH_REUSE_GRACE', 10),
    ],

    'two_factor' => [
        'enabled' => (bool) env('AUTH_2FA_ENABLED', true),
        'issuer' => env('AUTH_2FA_ISSUER'),
        'challenge_ttl_minutes' => (int) env('AUTH_2FA_CHALLENGE_TTL', 5),
        'max_attempts' => (int) env('AUTH_2FA_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('AUTH_2FA_LOCKOUT_MINUTES', 15),
        'recovery_codes' => (int) env('AUTH_2FA_RECOVERY_CODES', 8),
        // Tolerancia en ventanas de 30 s a cada lado del código actual.
        'window' => (int) env('AUTH_2FA_WINDOW', 1),
    ],

    'email_verification' => [
        // Enlace del correo: URL del frontend que recibe `?token=` y llama a POST /auth/email/verify.
        'url' => env('AUTH_EMAIL_VERIFICATION_URL'),
        'expire_minutes' => (int) env('AUTH_EMAIL_VERIFICATION_EXPIRE', 1440),
        'send_on_register' => (bool) env('AUTH_EMAIL_VERIFICATION_ON_REGISTER', true),
        // Al cambiar el correo de un usuario se anula `email_verified_at` y se reenvía la verificación.
        'send_on_email_change' => (bool) env('AUTH_EMAIL_VERIFICATION_ON_CHANGE', true),
        'resend_per_hour' => (int) env('AUTH_EMAIL_VERIFICATION_RESEND_PER_HOUR', 3),
        // false = el middleware `verified` deja pasar a todos (p. ej. en local).
        'enforce' => (bool) env('AUTH_EMAIL_VERIFICATION_ENFORCE', true),
    ],

];

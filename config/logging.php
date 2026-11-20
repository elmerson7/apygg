<?php

use App\Logging\RedactLogs;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'tap' => [RedactLogs::class],
            'level' => env('LOG_LEVEL', env('APP_ENV') === 'prod' ? 'error' : 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'tap' => [RedactLogs::class],
            'level' => env('LOG_LEVEL', env('APP_ENV') === 'prod' ? 'error' : 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'tap' => [RedactLogs::class],
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_SLACK_LEVEL', env('APP_ENV') === 'prod' ? 'error' : 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'tap' => [RedactLogs::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'tap' => [RedactLogs::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'tap' => [RedactLogs::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'tap' => [RedactLogs::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'driver' => 'single',
            'tap' => [RedactLogs::class],
            'level' => 'emergency',
        ],

        'sentry' => [
            'driver' => 'sentry',
            'tap' => [RedactLogs::class],
            // Niveles por entorno:
            // - dev: solo critical (evitar ruido en desarrollo)
            // - staging: error y superior
            // - prod: error y superior
            'level' => env('SENTRY_LOG_LEVEL', match (env('APP_ENV', 'dev')) {
                'dev' => 'critical',
                'staging', 'prod' => 'error',
                default => 'error',
            }),
            'replace_placeholders' => true,
        ],

        'sentry_logs' => [
            'driver' => 'sentry',
            'tap' => [RedactLogs::class],
            'level' => env('SENTRY_LOG_LEVEL', match (env('APP_ENV', 'dev')) {
                'dev' => 'critical',
                'staging', 'prod' => 'error',
                default => 'error',
            }),
            'replace_placeholders' => true,
        ],

        'activity' => [
            'driver' => 'activity',
            'tap' => [RedactLogs::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'security' => [
            'driver' => 'security',
            'tap' => [RedactLogs::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'auth' => [
            'driver' => 'auth',
            'tap' => [RedactLogs::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Filtro de Warnings de Deprecación
    |--------------------------------------------------------------------------
    |
    | Descarta los warnings de deprecación provenientes de dependencias externas
    | (vendor), que son ruido conocido de compatibilidad con PHP 8.5 y no
    | representan un problema de la aplicación.
    |
    | Lo aplican FilterDeprecationWarningsHandler (canales single/daily, se
    | cablean en AppServiceProvider) y FilterDeprecationWarningsProcessor
    | (canales monolog que declaran 'processors').
    |
    | Deja 'patterns' vacío para usar la lista por defecto del trait
    | FiltersDeprecationWarnings.
    |
    */

    'deprecation_filter' => [
        'enabled' => env('LOG_FILTER_DEPRECATIONS', true),
        'patterns' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redacción de datos personales y secretos
    |--------------------------------------------------------------------------
    |
    | Un único punto: App\Helpers\Redactor. Lo aplican los logs de BD (API,
    | actividad, seguridad), el processor de Monolog de todos los canales
    | (App\Logging\RedactingProcessor), el scrubber de Sentry y el back-office.
    | Ver docs/logging.md.
    |
    | 'mode':          'mask' (conserva una pista: j***@d***.com, ****1234) o
    |                  'remove' (borra la clave / sustituye por [REDACTED]).
    | 'keys':          nombre exacto de clave (sin distinguir mayúsculas).
    | 'key_fragments': se oculta toda clave que contenga el fragmento.
    | 'patterns':      detectados dentro de cualquier texto: email, card (Luhn),
    |                  phone (internacional con +), jwt, bearer.
    | 'max_depth':     profundidad máxima del recorrido; más abajo = [MAX_DEPTH].
    | 'max_string':    longitud máxima de una cadena analizada (el resto se corta).
    |
    */

    'redact' => [
        'mode' => env('LOG_REDACT_MODE', 'mask'),
        'keys' => [
            'password', 'current_password', 'new_password', 'password_confirmation',
            'token', 'access_token', 'refresh_token', 'api_token', 'remember_token',
            'secret', 'new_secret', 'client_secret', 'api_key', 'authorization',
            'cookie', 'key', 'credit_card', 'cvv', 'cvc', 'ssn', 'iban', 'pin', 'otp',
        ],
        'key_fragments' => [
            'password', 'passwd', '_token', 'secret', 'api_key', 'apikey', 'authorization', 'cookie',
            'recovery_codes', 'private_key', 'card_number', 'cardnumber', 'csrf', 'xsrf', 'iban',
        ],
        'patterns' => ['email', 'card', 'phone', 'jwt', 'bearer'],
        'max_depth' => 8,
        'max_string' => 10000,
    ],

];

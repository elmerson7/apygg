<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sentry DSN
    |--------------------------------------------------------------------------
    |
    | The DSN tells the SDK where to send the events to. If this value is not
    | provided, the SDK will try to read it from the SENTRY_LARAVEL_DSN
    | environment variable. If that variable also does not exist, the SDK
    | will not send any events.
    |
    */

    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN', null)),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | This value will be used to specify the environment in which Sentry
    | events are sent from. This value will be used to filter events in
    | the Sentry dashboard.
    |
    */

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    |
    | The release version of your application. This value will be used to
    | identify the version of your application in Sentry.
    |
    */

    'release' => env('SENTRY_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Traces Sample Rate
    |--------------------------------------------------------------------------
    |
    | When set, a percentage of transactions will be sent to Sentry.
    | For example, set this to 0.5 to send 50% of transactions.
    |
    | Ranges from 0.0 to 1.0.
    |
    */

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    /*
    |--------------------------------------------------------------------------
    | Profiles Sample Rate
    |--------------------------------------------------------------------------
    |
    | When set, a percentage of profiles will be sent to Sentry.
    | For example, set this to 0.5 to send 50% of profiles.
    |
    | Ranges from 0.0 to 1.0.
    |
    */

    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.0),

    /*
    |--------------------------------------------------------------------------
    | Send Default PII
    |--------------------------------------------------------------------------
    |
    | If this value is set to true, the SDK will send personally identifiable
    | information (PII) like user IDs, usernames, and email addresses to Sentry.
    |
    */

    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    /*
    |--------------------------------------------------------------------------
    | Before Send Callback
    |--------------------------------------------------------------------------
    |
    | This callback allows you to filter or modify events before they are sent
    | to Sentry. Return null to prevent the event from being sent.
    |
    | If you want to use the SentryPiiScrubber, uncomment the line below:
    | 'before_send' => [\App\Services\SentryPiiScrubber::class],
    |
    */

    // 'before_send' => null, // Removed: only include if you have a callback

    /*
    |--------------------------------------------------------------------------
    | Ignored Exceptions
    |--------------------------------------------------------------------------
    |
    | List of exception classes that should not be sent to Sentry.
    |
    */

    'ignore_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    |
    | Configure which breadcrumbs should be captured and sent to Sentry.
    |
    */

    'breadcrumbs' => [
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES', true),
        'sql_bindings' => env('SENTRY_BREADCRUMBS_SQL_BINDINGS', false),
        'http_client_requests' => env('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS', true),
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO', true),
        'command_info' => env('SENTRY_BREADCRUMBS_COMMAND_INFO', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    |
    | Configure which integrations should be enabled.
    |
    */

    'integrations' => [
        // Enable integrations as needed
    ],

];

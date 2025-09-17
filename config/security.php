<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security Logging Configuration
    |--------------------------------------------------------------------------
    |
    | This file is for configuring the security logging system. You can
    | enable or disable different types of security event logging here.
    |
    */

    'logging' => [
        'enabled' => env('SECURITY_LOGGING_ENABLED', true),
        
        'events' => [
            'suspicious_urls' => env('SECURITY_LOG_SUSPICIOUS_URLS', true),
            'malicious_headers' => env('SECURITY_LOG_MALICIOUS_HEADERS', true),
            'injection_attempts' => env('SECURITY_LOG_INJECTION_ATTEMPTS', true),
            'auth_errors' => env('SECURITY_LOG_AUTH_ERRORS', true),
            'cors_violations' => env('SECURITY_LOG_CORS_VIOLATIONS', true),
            'rate_limit_exceeded' => env('SECURITY_LOG_RATE_LIMIT', true),
            'webhook_security' => env('SECURITY_LOG_WEBHOOKS', true),
            'failed_logins' => env('SECURITY_LOG_FAILED_LOGINS', true),
            'unusual_activity' => env('SECURITY_LOG_UNUSUAL_ACTIVITY', true),
            'exceptions' => env('SECURITY_LOG_EXCEPTIONS', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Thresholds
    |--------------------------------------------------------------------------
    |
    | Configure thresholds for determining the severity of rate limit
    | violations and other security events.
    |
    */

    'thresholds' => [
        'rate_limit_violations' => [
            'low' => 1,
            'medium' => 5,
            'high' => 10,
            'critical' => 20,
        ],
        
        'failed_logins' => [
            'per_ip' => [
                'medium' => 5,
                'high' => 10,
                'critical' => 20,
            ],
            'per_email' => [
                'medium' => 3,
                'high' => 5,
                'critical' => 10,
            ],
        ],
        
        'suspicious_ip' => [
            'events_threshold' => 10,
            'time_window_hours' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pattern Detection
    |--------------------------------------------------------------------------
    |
    | Configure patterns for detecting various types of attacks and
    | suspicious activities.
    |
    */

    'patterns' => [
        'suspicious_urls' => [
            '/\.\.\//',                    // Path traversal
            '/\/admin/',                   // Admin access attempts
            '/\/wp-admin/',               // WordPress admin attempts
            '/\/wp-login/',               // WordPress login attempts
            '/\/phpmyadmin/',             // PhpMyAdmin attempts
            '/\/\.env/',                  // Environment file access
            '/\/config/',                 // Config file access
            '/\/\.git/',                  // Git repository access
            '/\/backup/',                 // Backup file access
            '/\/database/',               // Database access attempts
        ],
        
        'sql_injection' => [
            '/union\s+select/i',
            '/select\s+.*\s+from/i',
            '/insert\s+into/i',
            '/delete\s+from/i',
            '/update\s+.*\s+set/i',
            '/drop\s+table/i',
            '/or\s+1\s*=\s*1/i',
            '/and\s+1\s*=\s*1/i',
            '/\'\s+or\s+\'/i',
        ],
        
        'xss' => [
            '/<script[^>]*>/i',
            '/<\/script>/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<iframe[^>]*>/i',
            '/<object[^>]*>/i',
        ],
        
        'malicious_headers' => [
            'X-Forwarded-For' => '/[<>"\']/',
            'User-Agent' => '/[<>"\'][^<>"\']*[<>"\']/',
            'Referer' => '/javascript:/i',
            'X-Real-IP' => '/[^0-9\.\:a-fA-F]/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure cache settings for tracking violations and suspicious
    | activities.
    |
    */

    'cache' => [
        'violation_tracking_ttl' => 3600, // 1 hour in seconds
        'user_tracking_ttl' => 2592000,   // 30 days in seconds
        'ip_tracking_ttl' => 86400,       // 24 hours in seconds
    ],

];

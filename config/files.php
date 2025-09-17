<?php

return [
    /*
    |--------------------------------------------------------------------------
    | File Upload Configuration
    |--------------------------------------------------------------------------
    */

    'max_size' => env('FILE_MAX_SIZE', 10485760), // 10MB
    'max_size_avatar' => env('FILE_MAX_SIZE_AVATAR', 2097152), // 2MB
    'max_size_document' => env('FILE_MAX_SIZE_DOCUMENT', 52428800), // 50MB

    'allowed_mimes' => explode(',', env('FILE_ALLOWED_MIMES', 'jpg,jpeg,png,gif,pdf,doc,docx')),
    'allowed_avatar_mimes' => explode(',', env('FILE_ALLOWED_AVATAR_MIMES', 'jpg,jpeg,png,gif')),
    'allowed_document_mimes' => explode(',', env('FILE_ALLOWED_DOCUMENT_MIMES', 'pdf,doc,docx')),

    /*
    |--------------------------------------------------------------------------
    | Antivirus Configuration
    |--------------------------------------------------------------------------
    */

    'antivirus_enabled' => env('ANTIVIRUS_ENABLED', false),
    'antivirus_scanner' => env('ANTIVIRUS_SCANNER', 'mock'), // clamav, virustotal, mock
    'quarantine_bucket' => env('ANTIVIRUS_QUARANTINE_BUCKET'),
    'virustotal_api_key' => env('VIRUSTOTAL_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Cleanup Configuration
    |--------------------------------------------------------------------------
    */

    'temp_file_lifetime_hours' => 24,
    'infected_file_lifetime_days' => 30,
    'failed_upload_lifetime_hours' => 1,

    /*
    |--------------------------------------------------------------------------
    | URL Configuration
    |--------------------------------------------------------------------------
    */

    'default_url_expiration' => 3600, // 1 hour
    'download_url_expiration' => 3600, // 1 hour
    'upload_url_expiration' => 900, // 15 minutes
];

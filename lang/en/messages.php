<?php

/*
 * Non-validation API messages. Every key exists in lang/en and lang/es
 * (LangParidadTest enforces it). The front end translates by `code`; `message` is informative.
 */
return [
    'errors' => [
        'not_found' => 'Resource not found.',
        'endpoint_not_found' => 'Endpoint not found.',
        'endpoint_not_found_detail' => 'The requested endpoint does not exist.',
        'unauthenticated' => 'Unauthenticated',
        'unauthorized' => 'Unauthorized',
        'forbidden' => 'You do not have permission to perform this action',
        'token_invalid' => 'Invalid or expired token',
        'validation' => 'The given data was invalid.',
        'duplicate' => 'A record with this value already exists.',
        'server_error' => 'An internal server error occurred.',
        'generic' => 'An error occurred.',
    ],
    'success' => [
        'created' => 'Resource created successfully.',
        'updated' => 'Resource updated successfully.',
        'deleted' => 'Resource deleted successfully.',
        'restored' => 'Resource restored successfully.',
    ],
    'locale' => [
        'show' => 'Regional settings retrieved successfully',
        'updated' => 'Regional settings updated successfully',
        'tenant_show' => 'Company regional settings retrieved successfully',
        'tenant_updated' => 'Company regional settings updated successfully',
    ],
    'mail' => [
        'welcome' => [
            'subject' => 'Welcome to :app',
            'greeting' => 'Hello :name!',
            'intro' => 'Welcome to :app.',
            'body' => 'Your account has been created successfully and you can start using our services.',
            'help' => 'If you have any questions or need help, do not hesitate to contact us.',
            'salutation' => 'Regards, the :app team',
        ],
        'password_reset' => [
            'subject' => 'Reset Password',
            'greeting' => 'Hello :name,',
            'intro' => 'You are receiving this email because you requested a password reset.',
            'action' => 'Reset Password',
            'expires' => 'This link will expire in 60 minutes.',
            'ignore' => 'If you did not request this change, ignore this email.',
            'salutation' => 'Regards, :app',
        ],
        'email_verification' => [
            'subject' => 'Verify your email address',
            'greeting' => 'Hello :name,',
            'intro' => 'Confirm that this email address is yours to finish setting up your account.',
            'action' => 'Verify email',
            'expires' => 'This link will expire in :minutes minutes.',
            'ignore' => 'If you did not create this account, ignore this email.',
            'salutation' => 'Regards, :app',
        ],
    ],
];

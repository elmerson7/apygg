<?php

/*
| Texts of the notification types declared in config/notifications.php (docs/notifications.md).
| `types.<key>.title|body`; `:param` is replaced with the parameters passed to `notify()`.
*/

return [
    'greeting' => 'Hello :name!',
    'action' => 'View more',

    'types' => [
        'security_alert' => [
            'title' => 'Security alert',
            'body' => ':message',
        ],
        'account_activity' => [
            'title' => 'Activity on your account',
            'body' => ':message',
        ],
        'system_announcement' => [
            'title' => ':title',
            'body' => ':message',
        ],
        'export_ready' => [
            'title' => 'Export ready',
            'body' => 'Your export is ready to download.',
        ],
        'import_finished' => [
            'title' => 'Import finished',
            'body' => 'Your import has finished: :message',
        ],
        'data_export_ready' => [
            'title' => 'Your data export is ready',
            'body' => 'You can download it for the next :hours hours.',
        ],
        'account_deletion_scheduled' => [
            'title' => 'Account deletion scheduled',
            'body' => 'Your account will be deleted on :date. If this was not you, cancel the request or change your password.',
        ],
        'account_deletion_blocked' => [
            'title' => 'Your account could not be deleted',
            'body' => ':reason',
        ],
        'billing_trial_ending' => [
            'title' => 'Your trial ends soon',
            'body' => 'The :plan plan trial ends in :days day(s), on :date. The subscription will be charged afterwards.',
        ],
        'billing_renewal_upcoming' => [
            'title' => 'Your subscription renews soon',
            'body' => 'The :plan plan renews on :date for :amount.',
        ],
        'billing_invoice_paid' => [
            'title' => 'Invoice paid',
            'body' => 'We received the payment for invoice :number (:amount).',
        ],
        'billing_payment_failed' => [
            'title' => 'We could not charge your subscription',
            'body' => 'The payment for invoice :number (:amount) was declined. We will retry on :retry_date; you can pay it sooner.',
        ],
        'billing_final_warning' => [
            'title' => 'Last notice before your subscription is canceled',
            'body' => 'The payment for invoice :number (:amount) is still pending. The final retry is on :retry_date; if it fails, your subscription will end.',
        ],
        'billing_subscription_canceled' => [
            'title' => 'Subscription canceled',
            'body' => 'Your subscription to the :plan plan was canceled.',
        ],
        'billing_subscription_expired' => [
            'title' => 'Subscription expired',
            'body' => 'Your subscription to the :plan plan expired due to non-payment.',
        ],
    ],
];

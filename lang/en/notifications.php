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
    ],
];

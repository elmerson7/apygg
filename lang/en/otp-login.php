<?php

return [
    'mail' => [
        'subject' => 'Your sign-in code',
        'greeting' => 'Hello :name,',
        'intro' => 'Use this code to sign in. It can be used only once:',
        'expires' => 'It expires in :minutes minutes.',
        'ignore' => 'If you did not request it, ignore this message: nobody can sign in without the code.',
        'salutation' => 'Regards, :app',
    ],
    'sms' => 'Your sign-in code is :code. It expires in :minutes minutes. Do not share it.',
];

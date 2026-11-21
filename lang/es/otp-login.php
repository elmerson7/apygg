<?php

return [
    'mail' => [
        'subject' => 'Tu código para entrar',
        'greeting' => 'Hola :name,',
        'intro' => 'Usa este código para entrar. Solo vale una vez:',
        'expires' => 'Caduca en :minutes minutos.',
        'ignore' => 'Si no lo pediste tú, ignora este mensaje: nadie puede entrar sin el código.',
        'salutation' => 'Saludos, :app',
    ],
    'sms' => 'Tu código de acceso es :code. Caduca en :minutes minutos. No lo compartas.',
];

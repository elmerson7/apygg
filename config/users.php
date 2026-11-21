<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Documento de identidad
    |--------------------------------------------------------------------------
    |
    | Opcional y único si viene. El boilerplate NO impone formato de país: cada proyecto
    | fija su regla con USERS_IDENTITY_DOCUMENT_REGEX (expresión regular completa con
    | delimitadores, p. ej. `/^[0-9]{8}$/`). Vacío = cualquier texto de hasta `max` caracteres.
    |
    */
    'identity_document' => [
        'regex' => env('USERS_IDENTITY_DOCUMENT_REGEX', ''),
        'max' => 50,
    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default hash driver that will be used to hash
    | passwords for your application. By default, the bcrypt algorithm is
    | used; however, you remain free to modify this option if you wish.
    |
    | Supported: "bcrypt", "argon", "argon2id"
    |
    */

    'default' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Here you may specify the configuration options that should be used when
    | passwords are hashed using the Bcrypt algorithm. This will allow you
    | to control the amount of time it takes to hash the given password.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    |
    | Here you may specify the configuration options that should be used when
    | passwords are hashed using the Argon algorithm. These will allow you
    | to control the amount of time it takes to hash the given password.
    |
    | Memory: Cantidad de memoria en KB (recomendado: 65536 = 64MB)
    | Time: Número de iteraciones (recomendado: 4-6 para producción)
    | Threads: Hilos paralelos (recomendado: 2-4 según CPU)
    |
    */

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536), // 64MB
        'time' => (int) env('ARGON_TIME', 5),         // 5 iteraciones
        'threads' => (int) env('ARGON_THREADS', 3),   // 3 hilos
    ],

];

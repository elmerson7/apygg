<?php

/*
 * Proceso hijo de ConcurrentRunner. No es un test: arranca la app, espera la
 * barrera (archivo "go"), ejecuta la closure serializada y escribe su resultado.
 * Argumentos: <directorio> <índice> <timeout>
 */

use Illuminate\Contracts\Console\Kernel;
use Laravel\SerializableClosure\SerializableClosure;

[, $dir, $index, $timeout] = $argv;

require __DIR__.'/../../vendor/autoload.php';

$write = static fn (array $data) => file_put_contents("$dir/result-$index.json", json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR));

try {
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $app['config']->set('app.env', 'testing');

    /** @var SerializableClosure $wrapped */
    $wrapped = unserialize((string) file_get_contents("$dir/task.txt"));
    $task = $wrapped->getClosure();

    // Conexión abierta y caliente antes de la barrera: la carrera es de la operación, no del arranque.
    $app['db']->connection()->getPdo();

    touch("$dir/ready-$index");

    $deadline = microtime(true) + (int) $timeout;
    while (! file_exists("$dir/go")) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('La barrera no se abrió a tiempo.');
        }
        usleep(1_000);
    }

    $write(['ok' => true, 'result' => $task((int) $index), 'error' => null]);
} catch (Throwable $e) {
    $write(['ok' => false, 'result' => null, 'error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}

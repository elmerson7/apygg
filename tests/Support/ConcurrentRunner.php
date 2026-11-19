<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;

/**
 * Lanza N procesos PHP reales que ejecutan la misma operación a la vez (patrón barrera).
 *
 * Cada hijo arranca la app completa, avisa de que está listo y espera a que el
 * padre cree el archivo "go"; entonces todos ejecutan la closure y devuelven su
 * resultado como JSON. Sirve para probar carreras que un test secuencial no ve
 * (restricciones únicas, contadores, idempotencia).
 *
 * Los hijos usan la MISMA base de datos de tests que el padre, y los datos que
 * escriben quedan comprometidos: DatabaseTransactions/RefreshDatabase no
 * sirven entre procesos. El test debe usar una tabla limpia y borrar al final.
 *
 * Uso:
 *   $results = ConcurrentRunner::run(2, fn (int $i) => ['status' => ...]);
 *
 * La closure se desvincula de $this (el test Pest no existe en el hijo); no uses $this dentro. No puede capturar objetos no serializables (conexiones, modelos
 * con relaciones cargadas); pasa datos escalares. Recibe el índice del hijo (0..N-1)
 * y debe devolver un valor serializable a JSON.
 */
final class ConcurrentRunner
{
    /**
     * @param  \Closure(int): mixed  $task  se ejecuta dentro de cada hijo, con la app arrancada
     * @param  int  $timeout  segundos máximos para todo el ciclo
     * @return array<int, array{ok: bool, result: mixed, error: ?string}>
     */
    public static function run(int $processes, \Closure $task, int $timeout = 60): array
    {
        $dir = sys_get_temp_dir().'/apygg-concurrency-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        $payload = $dir.'/task.txt';
        file_put_contents($payload, serialize(new SerializableClosure(@\Closure::bind($task, null, null) ?? $task)));

        // Los hijos deben ver la misma BD que el padre (ya resuelta por TestCase).
        $connection = config('database.default');
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => (string) config("database.connections.{$connection}.database"),
            'DB_TEST_ISOLATION' => 'false',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'SCOUT_DRIVER' => 'null',
        ]);

        // Evita compartir el socket de BD del padre: los hijos abren la suya.
        DB::disconnect();

        $procs = [];
        for ($i = 0; $i < $processes; $i++) {
            $cmd = [PHP_BINARY, __DIR__.'/concurrency-worker.php', $dir, (string) $i, (string) $timeout];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            if (! is_resource($proc)) {
                throw new RuntimeException('No se pudo lanzar el proceso hijo.');
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $procs[$i] = ['proc' => $proc, 'pipes' => $pipes, 'out' => '', 'err' => ''];
        }

        try {
            // Barrera: esperar a que todos estén listos y soltarlos a la vez.
            $deadline = microtime(true) + $timeout;
            while (count(glob($dir.'/ready-*') ?: []) < $processes) {
                self::drain($procs);
                foreach ($procs as $i => $p) {
                    if (! proc_get_status($p['proc'])['running'] && ! file_exists($dir."/ready-$i")) {
                        self::drain($procs);
                        $detail = is_file($dir."/result-$i.json") ? file_get_contents($dir."/result-$i.json") : '';

                        throw new RuntimeException("El hijo $i terminó antes de la barrera: $detail {$procs[$i]['out']} {$procs[$i]['err']}");
                    }
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Timeout esperando a que los hijos lleguen a la barrera.');
                }
                usleep(20_000);
            }
            touch($dir.'/go');

            // Recoger resultados.
            $running = true;
            while ($running) {
                self::drain($procs);
                $running = false;
                foreach ($procs as $p) {
                    $running = $running || proc_get_status($p['proc'])['running'];
                }
                if ($running && microtime(true) > $deadline) {
                    throw new RuntimeException('Timeout esperando los resultados de los hijos.');
                }
                usleep(20_000);
            }
            self::drain($procs);

            $results = [];
            foreach ($procs as $i => $p) {
                $file = $dir."/result-$i.json";
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                $results[$i] = $decoded ?? ['ok' => false, 'result' => null, 'error' => "Sin resultado. stdout: {$p['out']} stderr: {$p['err']}"];
            }

            return $results;
        } finally {
            foreach ($procs as $p) {
                if (is_resource($p['proc'])) {
                    @proc_terminate($p['proc']);
                    @fclose($p['pipes'][1]);
                    @fclose($p['pipes'][2]);
                    @proc_close($p['proc']);
                }
            }
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    /**
     * Vacía las tuberías para que un hijo con mucha salida no se bloquee.
     *
     * @param  array<int, array<string, mixed>>  $procs
     */
    private static function drain(array &$procs): void
    {
        foreach ($procs as &$p) {
            $p['out'] .= (string) stream_get_contents($p['pipes'][1]);
            $p['err'] .= (string) stream_get_contents($p['pipes'][2]);
        }
    }
}

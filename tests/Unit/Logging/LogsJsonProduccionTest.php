<?php

use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;

/** Variables de una plantilla .env (sin comentarios). */
function variablesDe(string $archivo): array
{
    $vars = [];
    foreach (file(base_path($archivo), FILE_IGNORE_NEW_LINES) as $linea) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $linea, $m)) {
            $vars[$m[1]] = $m[2];
        }
    }

    return $vars;
}

test('la plantilla de producción manda los logs en JSON por stderr', function () {
    $env = variablesDe('.env.production.example');

    expect(explode(',', $env['LOG_STACK']))->toContain('stderr')->not->toContain('daily')->not->toContain('single')
        ->and($env['LOG_STDERR_FORMATTER'])->toBe(JsonFormatter::class)
        ->and(class_exists($env['LOG_STDERR_FORMATTER']))->toBeTrue();
});

test('el canal stderr con el formateador JSON escribe una línea JSON con trace_id y datos redactados', function () {
    $archivo = tempnam(sys_get_temp_dir(), 'logjson');
    config([
        'logging.channels.stderr.formatter' => JsonFormatter::class,
        'logging.channels.stderr.handler_with.stream' => $archivo,
    ]);
    Log::forgetChannel('stderr');

    Log::channel('stderr')->withContext(['trace_id' => 'traza-json-1'])
        ->warning('Pago rechazado', ['email' => 'juana.perez@dominio.com', 'password' => 'secreta']);

    $lineas = array_values(array_filter(explode("\n", (string) file_get_contents($archivo))));
    @unlink($archivo);
    Log::forgetChannel('stderr');

    expect($lineas)->toHaveCount(1);
    $evento = json_decode($lineas[0], true, flags: JSON_THROW_ON_ERROR);

    expect($evento['message'])->toBe('Pago rechazado')
        ->and($evento['level_name'])->toBe('WARNING')
        ->and($evento['context']['trace_id'])->toBe('traza-json-1')
        ->and($lineas[0])->not->toContain('juana.perez@dominio.com')->not->toContain('secreta');
});

test('el compose de producción rota los logs de los contenedores', function () {
    $compose = file_get_contents(base_path('deploy/compose.prod.yml'));

    expect($compose)->toContain('driver: json-file')->toContain('max-size:')->toContain('max-file:');
});

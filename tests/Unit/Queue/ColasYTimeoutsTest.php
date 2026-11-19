<?php

use App\Jobs\RunSecurityScanJob;
use App\Jobs\SendWebhookJob;
use App\Jobs\SendWelcomeEmailJob;
use App\Mail\SimpleMail;

/**
 * Las colas a las que se despacha algo deben tener quien las consuma, y `retry_after`
 * debe superar el timeout de cualquier job (si no, un job lento corre dos veces).
 */
function colasUsadasPorElCodigo(): array
{
    $colas = ['high', 'default', 'low']; // las que Laravel/Horizon dan por hechas
    $colas[] = (string) config('webhooks.queue.queue');
    $colas[] = (string) config('mail-system.queue');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        if (preg_match_all("/->onQueue\(\s*'([\w.-]+)'/", $code, $m)) {
            array_push($colas, ...$m[1]);
        }
        if (preg_match_all("/public\s+(?:\?string\s+)?\\\$queue\s*=\s*'([\w.-]+)'/", $code, $m)) {
            array_push($colas, ...$m[1]);
        }
    }

    return array_values(array_unique(array_filter($colas)));
}

test('cada cola a la que se despacha tiene un supervisor de Horizon en todos los entornos', function () {
    $entornos = config('horizon.environments');
    expect($entornos)->not->toBeEmpty();

    foreach ($entornos as $entorno => $supervisores) {
        $consumidas = collect($supervisores)->pluck('queue')->flatten()->all();

        foreach (colasUsadasPorElCodigo() as $cola) {
            expect(in_array($cola, $consumidas, true))->toBeTrue("la cola '{$cola}' no la consume ningún supervisor en '{$entorno}'");
        }
    }
});

test('la cola de webhooks sigue el nombre configurado en webhooks.php', function () {
    config(['webhooks.queue.queue' => 'webhooks']);

    expect(colasUsadasPorElCodigo())->toContain('webhooks');
});

test('retry_after de cada conexión redis es mayor que el timeout de cada job', function () {
    $timeouts = [
        SendWebhookJob::class => (new SendWebhookJob(1, 1))->timeout,
        RunSecurityScanJob::class => (new RunSecurityScanJob(1))->timeout,
        SendWelcomeEmailJob::class => (new SendWelcomeEmailJob(1))->timeout,
        SimpleMail::class => (new ReflectionClass(SimpleMail::class))->getDefaultProperties()['timeout'],
    ];

    // Cualquier otro job de app/Jobs que declare timeout también cuenta
    foreach (glob(app_path('Jobs/*.php')) as $archivo) {
        $clase = 'App\\Jobs\\'.basename($archivo, '.php');
        $props = (new ReflectionClass($clase))->getDefaultProperties();
        if (isset($props['timeout'])) {
            $timeouts[$clase] = max($timeouts[$clase] ?? 0, $props['timeout']);
        }
    }

    foreach (config('queue.connections') as $nombre => $conexion) {
        if (! isset($conexion['retry_after']) || ! str_starts_with($nombre, 'redis')) {
            continue;
        }

        foreach ($timeouts as $job => $timeout) {
            expect($conexion['retry_after'])->toBeGreaterThan($timeout, "{$nombre}: retry_after <= timeout de {$job}");
        }
    }
});

test('el timeout del job de webhooks cubre el timeout HTTP máximo y cabe en retry_after', function () {
    expect(config('webhooks.job_timeout'))->toBeGreaterThan(config('webhooks.max_timeout'));
    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan(config('webhooks.job_timeout'));
    expect((new SendWebhookJob(1, 1))->timeout)->toBe((int) config('webhooks.job_timeout'));
});

test('el timeout del supervisor de webhooks de Horizon cubre el del job', function () {
    foreach (config('horizon.environments') as $supervisores) {
        expect($supervisores['supervisor-webhooks']['timeout'])->toBeGreaterThanOrEqual((int) config('webhooks.job_timeout'));
    }
});

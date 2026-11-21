<?php

use App\Support\ApiPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Métricas Prometheus (docs/metrics.md): apagadas por defecto, nunca públicas, contadores en Redis.
 */

beforeEach(function () {
    $this->prefijo = 'metrics_test_'.bin2hex(random_bytes(4)).':';
    config([
        'metrics.key_prefix' => $this->prefijo,
        'metrics.enabled' => true,
        'metrics.token' => 'token-de-prueba',
        'metrics.allowed_ips' => [],
    ]);
});

afterEach(function () {
    $redis = Redis::connection(config('metrics.redis_connection'));

    foreach (['requests', 'duration_buckets', 'duration_sum_us'] as $key) {
        $redis->del($this->prefijo.$key);
    }
});

function metricas(array $headers = ['Authorization' => 'Bearer token-de-prueba']): string
{
    return test()->withHeaders($headers)->get('/metrics')->assertOk()->getContent();
}

test('apagado por defecto: la ruta responde 404', function () {
    config(['metrics.enabled' => false]);

    $this->withHeaders(['Authorization' => 'Bearer token-de-prueba'])->get('/metrics')->assertNotFound();
});

test('el valor por defecto de la configuración es apagado', function () {
    expect((require base_path('config/metrics.php'))['enabled'])->toBeFalse();
});

test('encendido pero sin token ni IPs configurados responde siempre 403', function () {
    config(['metrics.token' => null, 'metrics.allowed_ips' => []]);

    $this->get('/metrics')->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->withHeaders(['Authorization' => 'Bearer '])->get('/metrics')->assertForbidden();
});

test('sin token o con un token incorrecto se deniega', function () {
    $this->get('/metrics')->assertForbidden();
    $this->withHeaders(['Authorization' => 'Bearer otro'])->get('/metrics')->assertForbidden();
});

test('con el token correcto devuelve texto de Prometheus', function () {
    $res = $this->withHeaders(['Authorization' => 'Bearer token-de-prueba'])->get('/metrics')->assertOk();

    expect($res->headers->get('Content-Type'))->toContain('text/plain')
        ->and($res->getContent())->toContain('# TYPE app_http_requests_total counter')
        ->and($res->getContent())->toContain('app_db_up 1');
});

test('una IP de la lista blanca (CIDR) entra sin token y otra no', function () {
    config(['metrics.token' => null, 'metrics.allowed_ips' => ['10.9.0.0/16']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.9.4.2'])->get('/metrics')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '10.8.4.2'])->get('/metrics')->assertForbidden();
});

test('cuenta las peticiones por método, plantilla de ruta y estado', function () {
    $this->get('/health')->assertOk();
    $this->get('/health')->assertOk();

    $texto = metricas();

    expect($texto)->toContain('app_http_requests_total{method="GET",route="/health",status="200"} 2');
});

test('el histograma de latencia es acumulado y cierra en +Inf con sum y count', function () {
    $this->get('/health')->assertOk();

    $texto = metricas();

    expect($texto)->toContain('app_http_request_duration_seconds_bucket{method="GET",route="/health",le="+Inf"} 1')
        ->and($texto)->toContain('app_http_request_duration_seconds_count{method="GET",route="/health"} 1')
        ->and($texto)->toContain('app_http_request_duration_seconds_sum{method="GET",route="/health"}');

    // Acumulado: el cubo más grande finito no puede superar a +Inf y ninguno decrece.
    preg_match_all('/_bucket\{method="GET",route="\/health",le="[^"]+"\} (\d+)/', $texto, $m);
    $valores = array_map('intval', $m[1]);
    $ordenados = $valores;
    sort($ordenados);

    expect($valores)->toBe($ordenados);
});

test('las rutas sin coincidencia se agrupan en una sola etiqueta (sin cardinalidad infinita)', function () {
    $this->get('/no-existe-'.bin2hex(random_bytes(3)))->assertNotFound();
    $this->get('/no-existe-'.bin2hex(random_bytes(3)))->assertNotFound();

    expect(metricas())->toContain('route="unmatched",status="404"} 2');
});

test('apagado no registra nada y /metrics no se cuenta a sí misma', function () {
    config(['metrics.enabled' => false]);
    $this->get('/health')->assertOk();
    config(['metrics.enabled' => true]);

    $texto = metricas();
    metricas();

    expect($texto)->not->toContain('route="/health"')
        ->and(metricas())->not->toContain('route="/metrics"');
});

test('expone tamaño de cola, jobs fallidos y conexiones de la BD', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'x',
        'failed_at' => now(),
    ]);

    $texto = metricas();

    expect($texto)->toContain('app_queue_size{queue="default"}')
        ->and($texto)->toContain('app_failed_jobs 1')
        ->and($texto)->toContain('app_db_up 1')
        ->and($texto)->toContain('app_db_connections ');
});

test('la ruta /metrics nunca lleva el prefijo de versión', function () {
    expect(ApiPath::isUnprefixed('metrics'))->toBeTrue();
});

test('el espacio de nombres de las métricas se configura', function () {
    config(['metrics.namespace' => 'mi-saas']);

    expect(metricas())->toContain('mi_saas_db_up 1');
});

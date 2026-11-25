<?php

use App\Contracts\IdempotencyInterface;
use App\Models\Tenant;
use App\Support\Idempotency\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * IdempotencyService directo (sin HTTP): espera configurable, TTL y bloqueo, `complete` con token, reserva
 * caducada retomada limpia, ámbito por tenant y purga por lotes. Casos que las pruebas de mutación mostraron
 * sin cubrir desde los tests del middleware.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->servicio = app(IdempotencyInterface::class);
    Carbon::setTestNow(now()->startOfSecond());
});

afterEach(fn () => Carbon::setTestNow());

function reservar($test, string $clave = 'k', ?int $tenant = null, string $metodo = 'POST', string $ruta = '/x', string $huella = 'h'): Reservation
{
    // request_hash es un sha256 de longitud fija (char 64), como el que calcula el middleware.
    return $test->servicio->begin($tenant, 'user:1', $clave, $metodo, $ruta, hash('sha256', $huella));
}

function filaIdem(int $id): object
{
    return DB::table('idempotency_keys')->where('id', $id)->first();
}

test('sin espera configurada una clave en curso responde al instante', function () {
    reservar($this);

    $inicio = microtime(true);
    expect(reservar($this)->outcome)->toBe(Reservation::IN_PROGRESS)
        ->and(microtime(true) - $inicio)->toBeLessThan(0.1);
});

test('con wait_ms espera ese tiempo a que termine la clave en curso antes de responder en curso', function () {
    config(['idempotency.wait_ms' => 200]);
    reservar($this);

    $inicio = microtime(true);
    $resultado = reservar($this);
    $espera = microtime(true) - $inicio;

    expect($resultado->outcome)->toBe(Reservation::IN_PROGRESS)
        ->and($espera)->toBeGreaterThanOrEqual(0.19)
        ->and($espera)->toBeLessThan(2.0);
});

test('con wait_ms una clave nueva se adquiere a la primera', function () {
    config(['idempotency.wait_ms' => 200]);

    expect(reservar($this, 'nueva')->outcome)->toBe(Reservation::ACQUIRED);
});

test('la reserva queda bloqueada lock_seconds y como mínimo un segundo', function () {
    $r = reservar($this, 'a');
    expect(Carbon::parse(filaIdem($r->id)->expires_at)->equalTo(now()->addSeconds(120)))->toBeTrue();

    config(['idempotency.lock_seconds' => 0]);
    $r = reservar($this, 'b');
    expect(Carbon::parse(filaIdem($r->id)->expires_at)->equalTo(now()->addSecond()))->toBeTrue();
});

test('complete con el token de la reserva guarda la respuesta y la mantiene ttl_hours, como mínimo una hora', function () {
    $r = reservar($this, 'a');
    $this->travel(5)->seconds();

    expect($this->servicio->complete($r, 201, ['X-A' => ['1']], 'cuerpo'))->toBeTrue();

    $fila = filaIdem($r->id);
    expect($fila->state)->toBe('completed')
        ->and(Carbon::parse($fila->expires_at)->equalTo(now()->addHours(24)))->toBeTrue()
        ->and(Carbon::parse($fila->updated_at)->equalTo(now()))->toBeTrue();

    config(['idempotency.ttl_hours' => 0]);
    $r = reservar($this, 'b');
    $this->servicio->complete($r, 201, [], 'x');
    expect(Carbon::parse(filaIdem($r->id)->expires_at)->equalTo(now()->addHour()))->toBeTrue();
});

test('complete sin la reserva vigente no escribe y devuelve false', function () {
    $r = reservar($this, 'a');
    $ajena = new Reservation(Reservation::ACQUIRED, $r->id, 'otro-token');

    expect($this->servicio->complete($ajena, 201, [], 'x'))->toBeFalse()
        ->and(filaIdem($r->id)->state)->toBe('processing');

    $this->servicio->complete($r, 201, [], 'x');
    expect($this->servicio->complete($r, 500, [], 'y'))->toBeFalse();
});

test('una respuesta guardada se repite con su estado, cabeceras y cuerpo', function () {
    $r = reservar($this);
    $this->servicio->complete($r, 202, ['X-A' => ['1']], 'cuerpo');

    $repetida = reservar($this);

    expect($repetida->outcome)->toBe(Reservation::REPLAY)
        ->and($repetida->id)->toBe($r->id)
        ->and($repetida->status)->toBe(202)
        ->and($repetida->headers)->toBe(['X-A' => ['1']])
        ->and($repetida->body)->toBe('cuerpo');
});

test('una clave caducada se retoma limpia: nueva petición, en proceso y sin la respuesta anterior', function () {
    $r = reservar($this, 'k', null, 'POST', '/vieja', 'h-vieja');
    $this->servicio->complete($r, 201, ['X-A' => ['1']], 'vieja');
    $this->travel(25)->hours();

    $nueva = reservar($this, 'k', null, 'PATCH', '/nueva', 'h-nueva');
    $fila = filaIdem($r->id);

    expect($nueva->outcome)->toBe(Reservation::ACQUIRED)
        ->and($nueva->id)->toBe($r->id)
        ->and($nueva->token)->not->toBe($r->token)
        ->and([$fila->method, $fila->path, $fila->request_hash, $fila->state])->toBe(['PATCH', '/nueva', hash('sha256', 'h-nueva'), 'processing'])
        ->and([$fila->response_status, $fila->response_headers, $fila->response_body])->toBe([null, null, null])
        ->and(Carbon::parse($fila->expires_at)->equalTo(now()->addSeconds(120)))->toBeTrue()
        ->and(Carbon::parse($fila->created_at)->equalTo(now()))->toBeTrue()
        ->and(Carbon::parse($fila->updated_at)->equalTo(now()))->toBeTrue();
});

test('la misma clave se repite dentro de su tenant y es independiente sin tenant', function () {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
    $r = reservar($this, 'k', $tenant->id);
    $this->servicio->complete($r, 201, [], 'del tenant');

    expect(reservar($this, 'k', $tenant->id)->body)->toBe('del tenant')
        ->and(reservar($this, 'k', null)->outcome)->toBe(Reservation::ACQUIRED);
});

test('la purga borra por lotes todas las caducadas y devuelve el total', function () {
    foreach (range(1, 5) as $i) {
        reservar($this, "vieja-{$i}");
    }
    $this->travel(1)->hours();
    reservar($this, 'vigente');

    expect($this->servicio->purgeExpired(2))->toBe(5)
        ->and(DB::table('idempotency_keys')->pluck('key')->all())->toBe(['vigente'])
        ->and($this->servicio->purgeExpired())->toBe(0);
});

test('el comando de limpieza informa cuántas borraría en modo prueba y cuántas borró', function () {
    reservar($this, 'vieja-1');
    reservar($this, 'vieja-2');
    $this->travel(1)->hours();
    reservar($this, 'vigente');

    $this->artisan('idempotency:clean --dry-run')
        ->expectsOutputToContain('se borrarían 2 claves caducadas')
        ->assertSuccessful();
    expect(DB::table('idempotency_keys')->count())->toBe(3);

    $this->artisan('idempotency:clean')
        ->expectsOutputToContain('Eliminadas 2 claves de idempotencia caducadas')
        ->assertSuccessful();
    expect(DB::table('idempotency_keys')->pluck('key')->all())->toBe(['vigente']);
});

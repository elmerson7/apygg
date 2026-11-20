<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ConcurrentRunner;

afterEach(function () {
    DB::table('idempotency_keys')->where('key', 'like', 'carrera-%')->delete();
    DB::table('webhooks')->where('name', 'like', 'Carrera %')->delete();
    DB::table('users')->where('email', 'like', 'carrera-idem-%')->delete();
});

function carreraUsuario(): User
{
    return User::factory()->create(['email' => 'carrera-idem-'.bin2hex(random_bytes(4)).'@example.com']);
}

test('dos peticiones simultaneas con la misma clave ejecutan una sola vez', function () {
    $user = carreraUsuario();
    $token = JWTAuth::fromUser($user);
    $key = 'carrera-'.bin2hex(random_bytes(4));
    $nombre = 'Carrera '.$key;

    $results = ConcurrentRunner::run(2, function (int $i) use ($token, $key, $nombre) {
        config(['idempotency.wait_ms' => 0]);

        $response = app(Kernel::class)->handle(Request::create('/webhooks', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ], json_encode(['name' => $nombre, 'url' => 'https://example.com/hook'])));

        return ['status' => $response->getStatusCode(), 'replayed' => $response->headers->get('Idempotent-Replayed')];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $status = collect($results)->pluck('result.status')->sort()->values()->all();

    // Una crea (201); la otra o ve la clave en curso (409) o, si llegó después de terminar, la repite (201 replay).
    expect($status[1])->toBeIn([201, 409])
        ->and($status[0])->toBeIn([201, 409], json_encode($results))
        ->and(DB::table('webhooks')->where('name', $nombre)->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->where('key', $key)->count())->toBe(1);
});

test('con espera configurada la segunda peticion devuelve la respuesta guardada de la primera', function () {
    $user = carreraUsuario();
    $token = JWTAuth::fromUser($user);
    $key = 'carrera-'.bin2hex(random_bytes(4));
    $nombre = 'Carrera '.$key;

    $results = ConcurrentRunner::run(3, function (int $i) use ($token, $key, $nombre) {
        config(['idempotency.wait_ms' => 5000]);

        $response = app(Kernel::class)->handle(Request::create('/webhooks', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ], json_encode(['name' => $nombre, 'url' => 'https://example.com/hook'])));

        return [
            'status' => $response->getStatusCode(),
            'replayed' => $response->headers->get('Idempotent-Replayed'),
            'secret' => json_decode((string) $response->getContent(), true)['data']['secret'] ?? null,
        ];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($results)->pluck('result.status')->all())->toBe([201, 201, 201], json_encode($results))
        ->and(collect($results)->pluck('result.replayed')->filter()->count())->toBe(2)
        ->and(collect($results)->pluck('result.secret')->unique()->count())->toBe(1)
        ->and(DB::table('webhooks')->where('name', $nombre)->count())->toBe(1);
});

test('dos procesos que retoman a la vez una reserva caducada solo ejecutan uno', function () {
    $user = carreraUsuario();
    $token = JWTAuth::fromUser($user);
    $key = 'carrera-'.bin2hex(random_bytes(4));
    $nombre = 'Carrera '.$key;
    $body = json_encode(['name' => $nombre, 'url' => 'https://example.com/hook']);
    $hash = hash('sha256', json_encode(['body' => ['name' => $nombre, 'url' => 'https://example.com/hook'], 'files' => [], 'query' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    DB::table('idempotency_keys')->insert([
        'tenant_id' => null, 'actor' => 'user:'.$user->id, 'key' => $key, 'token' => (string) Str::uuid(),
        'method' => 'POST', 'path' => '/webhooks', 'request_hash' => $hash,
        'state' => 'processing', 'expires_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $results = ConcurrentRunner::run(3, function (int $i) use ($token, $key, $body) {
        config(['idempotency.wait_ms' => 0]);

        $response = app(Kernel::class)->handle(Request::create('/webhooks', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ], $body));

        return ['status' => $response->getStatusCode()];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(DB::table('webhooks')->where('name', $nombre)->count())->toBe(1, json_encode($results));
});

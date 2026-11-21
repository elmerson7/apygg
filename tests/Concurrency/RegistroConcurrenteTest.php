<?php

use App\Models\User;
use App\Support\ApiPath;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentRunner;

/*
 * Carreras reales: varios procesos PHP a la vez contra la BD de tests.
 * Los datos quedan comprometidos, así que se limpia a mano (no hay rollback).
 */

afterEach(function () {
    DB::table('users')->where('email', 'like', 'carrera-%@example.com')->delete();
});

test('dos registros simultáneos con el mismo email crean un solo usuario y el otro recibe 422', function () {
    $email = 'carrera-'.bin2hex(random_bytes(4)).'@example.com';

    $results = ConcurrentRunner::run(2, function (int $i) use ($email) {
        $response = app(Kernel::class)->handle(
            Request::create(ApiPath::with('/auth/register'), 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '10.0.0.'.($i + 1),
            ], json_encode([
                'name' => "Carrera $i",
                'email' => $email,
                'password' => 'Clave12345',
                'password_confirmation' => 'Clave12345',
            ]))
        );

        return ['status' => $response->getStatusCode(), 'body' => substr((string) $response->getContent(), 0, 300)];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $statuses = collect($results)->pluck('result.status')->sort()->values()->all();

    expect($statuses)->toBe([201, 422], json_encode($results));
    expect(User::where('email', $email)->count())->toBe(1);
});

<?php

use App\Models\User;
use App\Models\UserSession;
use App\Support\ApiPath;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentRunner;

/*
 * Carrera real: dos refresh simultáneos con el MISMO refresh token. La BD (compare-and-swap) deja
 * pasar a uno solo; el otro es una carrera dentro de la gracia (401 `refresh_superseded`) y la sesión sigue viva.
 */

afterEach(function () {
    DB::table('users')->where('email', 'like', 'carrera-%@example.com')->delete(); // cascada: sesiones
});

test('dos refresh simultaneos con el mismo token: uno recibe 200, el otro 401 refresh_superseded y la sesion sigue viva', function () {
    $user = User::factory()->create(['email' => 'carrera-'.bin2hex(random_bytes(4)).'@example.com']);

    $login = $this->postJson('/auth/login', ['login' => $user->email, 'password' => 'password'])->assertOk();
    $refresh = $login->json('data.refresh_token');

    $results = ConcurrentRunner::run(2, function (int $i) use ($refresh) {
        $response = app(Kernel::class)->handle(
            Request::create(ApiPath::with('/auth/refresh'), 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '10.0.1.'.($i + 1),
            ], json_encode(['refresh_token' => $refresh]))
        );

        return ['status' => $response->getStatusCode(), 'code' => json_decode((string) $response->getContent(), true)['code'] ?? null];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $statuses = collect($results)->pluck('result.status')->sort()->values()->all();

    expect($statuses)->toBe([200, 401], json_encode($results));

    // El perdedor es una carrera legítima (dentro de la gracia): código estable y sesión VIVA.
    expect(collect($results)->where('result.status', 401)->pluck('result.code')->all())->toBe(['refresh_superseded']);

    $sesion = UserSession::where('user_id', $user->id)->sole();
    expect($sesion->revoked_at)->toBeNull();
});

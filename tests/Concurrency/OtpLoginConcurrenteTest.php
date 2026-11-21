<?php

use App\Models\LoginOtp;
use App\Models\User;
use App\Support\ApiPath;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ConcurrentRunner;

/*
 * Carrera real del login por código (módulo otp-login): un código de un solo uso canjeado a la vez desde dos procesos.
 */

afterEach(function () {
    DB::table('users')->where('email', 'like', 'carrera-%@example.com')->delete(); // cascada: otps y sesiones
});

test('un codigo de login canjeado a la vez desde dos procesos abre una sola sesion', function () {
    $user = User::factory()->create(['email' => 'carrera-'.bin2hex(random_bytes(4)).'@example.com']);
    $hash = hash_hmac('sha256', 'mail|'.$user->email, (string) config('app.key'));
    LoginOtp::create(['user_id' => $user->id, 'channel' => 'mail', 'destination_hash' => $hash, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10)]);
    $email = $user->email;

    $results = ConcurrentRunner::run(2, function (int $i) use ($email) {
        config(['otp-login.enabled' => true]);

        $response = app(Kernel::class)->handle(
            Request::create(ApiPath::with('/auth/otp/verify'), 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '10.0.2.'.($i + 1),
            ], json_encode(['email' => $email, 'code' => '123456']))
        );

        return ['status' => $response->getStatusCode()];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($results)->pluck('result.status')->sort()->values()->all())->toBe([200, 401], json_encode($results));
    expect(DB::table('user_sessions')->where('user_id', $user->id)->count())->toBe(1);
});

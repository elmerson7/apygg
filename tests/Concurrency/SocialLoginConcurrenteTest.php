<?php

use App\Contracts\SocialIdentityResolverInterface;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\ApiPath;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentRunner;
use Tests\Support\FakeSocialIdentityResolver;

/*
 * Carrera real del login social (módulo social-login): dos primeros logins simultáneos de la misma persona.
 */

afterEach(function () {
    DB::table('users')->where('email', 'like', 'carrera-%@example.com')->delete(); // cascada: vinculaciones y sesiones
});

test('dos primeros logins sociales simultaneos de la misma persona crean un solo usuario y una vinculacion', function () {
    $email = 'carrera-'.bin2hex(random_bytes(4)).'@example.com';
    $sub = 'sub-'.bin2hex(random_bytes(4));

    $results = ConcurrentRunner::run(2, function (int $i) use ($email, $sub) {
        config(['social-login.enabled' => true, 'social-login.auto_register' => true, 'social-login.providers.google.client_ids' => ['x']]);

        app()->instance(SocialIdentityResolverInterface::class, new FakeSocialIdentityResolver($sub, $email));

        $response = app(Kernel::class)->handle(
            Request::create(ApiPath::with('/auth/social/google'), 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '10.0.3.'.($i + 1),
            ], json_encode(['id_token' => 'x']))
        );

        return ['status' => $response->getStatusCode(), 'body' => substr((string) $response->getContent(), 0, 200)];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($results)->pluck('result.status')->all())->toBe([200, 200], json_encode($results));
    expect(User::where('email', $email)->count())->toBe(1);
    expect(SocialAccount::where('provider_user_id', $sub)->count())->toBe(1);
});

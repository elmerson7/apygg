<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

function loginParaLimites(User $user, string $ip): array
{
    $respuesta = test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/auth/login', ['login' => $user->email, 'password' => 'password'])
        ->assertOk();

    return [$respuesta->json('data.access_token'), $respuesta->json('data.refresh_token')];
}

test('la renovación del token tiene su propio límite por IP y responde 429 al pasarlo', function () {
    config(['rate-limiting.limits.refresh.max_attempts' => 3]);

    foreach (range(1, 3) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/auth/refresh', ['refresh_token' => 'no-vale'])
            ->assertStatus(401);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->postJson('/auth/refresh', ['refresh_token' => 'no-vale'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    // Otra IP no comparte contador
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
        ->postJson('/auth/refresh', ['refresh_token' => 'no-vale'])
        ->assertStatus(401);

    expect((int) RateLimiter::attempts('rate_limit:refresh:ip:203.0.113.10'))->toBe(3);
});

test('la renovación no gasta el cupo de escritura genérico', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
        ->postJson('/auth/refresh', ['refresh_token' => 'no-vale'])
        ->assertHeader('X-RateLimit-Limit', (string) config('rate-limiting.limits.refresh.max_attempts'));

    expect((int) RateLimiter::attempts('rate_limit:write:ip:203.0.113.12'))->toBe(0);
});

test('el cambio de contraseña tiene un límite por usuario más estricto y responde 429 al pasarlo', function () {
    config(['rate-limiting.limits.sensitive.max_attempts' => 2]);
    $user = User::factory()->create();
    [$access] = loginParaLimites($user, '203.0.113.20');

    $cambio = fn () => $this->withHeader('Authorization', "Bearer {$access}")
        ->postJson('/auth/change-password', [
            'current_password' => 'mala',
            'password' => 'Nueva12345',
            'password_confirmation' => 'Nueva12345',
        ]);

    auth()->forgetGuards();
    expect($cambio()->status())->not->toBe(429);
    auth()->forgetGuards();
    expect($cambio()->status())->not->toBe(429);
    auth()->forgetGuards();
    $cambio()->assertStatus(429);

    expect((int) RateLimiter::attempts("rate_limit:sensitive:user:{$user->id}"))->toBe(2);
});

test('los límites de renovación y cambio de contraseña salen de la configuración', function () {
    expect(config('rate-limiting.patterns.refresh'))->toBe(['auth/refresh'])
        ->and(config('rate-limiting.patterns.sensitive'))->toBe(['auth/change-password'])
        ->and(config('rate-limiting.limits.refresh.by'))->toBe('ip')
        ->and(config('rate-limiting.limits.sensitive.by'))->toBe('user');
});

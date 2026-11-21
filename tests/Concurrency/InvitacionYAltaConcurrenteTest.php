<?php

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Support\ApiPath;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ConcurrentRunner;

/*
 * Carreras reales (procesos PHP a la vez) del alta de empresa y de la aceptación de invitaciones.
 * Los datos quedan comprometidos: se limpian a mano.
 */

beforeEach(function () {
    $this->sufijo = bin2hex(random_bytes(4));
    $this->seed(RoleSeeder::class);
});

afterEach(function () {
    DB::table('tenants')->where('slug', 'like', 'carrera-%')->delete(); // cascada: membresías e invitaciones
    DB::table('users')->where('email', 'like', 'carrera-%@example.com')->delete();
});

test('dos altas simultáneas del mismo slug crean una sola empresa y la otra recibe 422', function () {
    $slug = 'carrera-'.$this->sufijo;
    $tokens = [];
    foreach ([0, 1] as $i) {
        $u = User::factory()->create(['email' => "carrera-{$this->sufijo}-{$i}@example.com"]);
        $tokens[$i] = JWTAuth::fromUser($u);
    }

    $results = ConcurrentRunner::run(2, function (int $i) use ($tokens, $slug) {
        config(['multi-tenancy.enabled' => true, 'multi-tenancy.onboarding.signup.enabled' => true]);

        $response = app(Kernel::class)->handle(Request::create(ApiPath::with('/tenants'), 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens[$i],
            'REMOTE_ADDR' => '10.1.0.'.($i + 1),
        ], json_encode(['name' => "Carrera $i", 'slug' => $slug])));

        return ['status' => $response->getStatusCode(), 'body' => substr((string) $response->getContent(), 0, 300)];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($results)->pluck('result.status')->sort()->values()->all())->toBe([201, 422], json_encode($results));
    expect(Tenant::where('slug', $slug)->count())->toBe(1)
        ->and(DB::table('tenant_user')->where('tenant_id', Tenant::where('slug', $slug)->value('id'))->count())->toBe(1);
});

test('dos aceptaciones simultáneas de la misma invitación dejan una sola membresía', function () {
    config(['multi-tenancy.enabled' => true]);
    $tenant = Tenant::create(['name' => 'Carrera', 'slug' => 'carrera-'.$this->sufijo, 'status' => 'active']);
    $email = "carrera-{$this->sufijo}@example.com";
    $user = User::factory()->create(['email' => $email]);
    $token = str_repeat('c', 64);

    DB::table('tenant_invitations')->insert([
        'tenant_id' => $tenant->id, 'email' => $email, 'role' => 'member', 'token_hash' => TenantInvitation::hashToken($token),
        'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $jwt = JWTAuth::fromUser($user);

    $results = ConcurrentRunner::run(2, function (int $i) use ($jwt, $token) {
        config(['multi-tenancy.enabled' => true]);

        $response = app(Kernel::class)->handle(Request::create(ApiPath::with('/tenant/invitations/accept'), 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
            'REMOTE_ADDR' => '10.1.0.'.($i + 1),
        ], json_encode(['token' => $token])));

        return ['status' => $response->getStatusCode(), 'body' => substr((string) $response->getContent(), 0, 300)];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($results)->pluck('result.status')->sort()->values()->all())->toBe([200, 409], json_encode($results));
    expect(DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('tenant_invitations')->where('tenant_id', $tenant->id)->whereNotNull('accepted_at')->count())->toBe(1);
});

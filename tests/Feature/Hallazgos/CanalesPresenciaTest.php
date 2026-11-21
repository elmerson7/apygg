<?php

use App\Enums\TenantMembershipStatus;
use App\Http\Controllers\Auth\BroadcastAuthController;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

/**
 * Ejecuta el callback registrado en routes/channels.php para ese patrón (el mismo que Echo/Reverb
 * invoca tras normalizar `presence-`). Devuelve el payload o false si se deniega.
 *
 * @return array<string, mixed>|false
 */
function autorizarCanal(User $user, string $patron, mixed ...$parametros): array|false
{
    $driver = Broadcast::driver();
    require base_path('routes/channels.php');

    $canales = (new ReflectionProperty(Broadcaster::class, 'channels'))->getValue($driver);
    $callback = $canales[$patron] ?? throw new RuntimeException("Canal {$patron} no registrado");

    return $callback($user, ...$parametros) ?: false;
}

test('un miembro activo entra al canal de presencia de su tenant y no se expone su correo', function () {
    $this->enableTenancy();
    $a = $this->makeTenant('acme');
    $user = User::factory()->create();
    $this->addMember($a, $user);

    $payload = autorizarCanal($user, 'tenant.{tenantId}', $a->id);

    expect($payload)->toBe(['id' => $user->id, 'name' => $user->name])
        ->and($payload)->not->toHaveKey('email');
});

test('un usuario de otro tenant no entra al canal de presencia', function () {
    $this->enableTenancy();
    $a = $this->makeTenant('acme');
    $b = $this->makeTenant('beta');
    $deB = User::factory()->create();
    $this->addMember($b, $deB);

    expect(autorizarCanal($deB, 'tenant.{tenantId}', $a->id))->toBeFalse();
});

test('un miembro suspendido no entra al canal de presencia de su tenant', function () {
    $this->enableTenancy();
    $a = $this->makeTenant('acme');
    $user = User::factory()->create();
    $this->addMember($a, $user, TenantMembershipStatus::Suspended);

    expect(autorizarCanal($user, 'tenant.{tenantId}', $a->id))->toBeFalse();
});

test('con multiempresa activo el canal de presencia de plataforma se deniega', function () {
    $this->enableTenancy();

    expect(autorizarCanal(User::factory()->create(), 'online'))->toBeFalse();
});

test('con multiempresa apagado el canal de plataforma admite al usuario sin correo en el payload', function () {
    $user = User::factory()->create();

    expect(autorizarCanal($user, 'online'))->toBe(['id' => $user->id, 'name' => $user->name]);
});

test('no hay canales públicos registrados: los antiguos notifications y test-messages ya no existen', function () {
    $driver = Broadcast::driver();
    require base_path('routes/channels.php');

    $canales = array_keys((new ReflectionProperty(Broadcaster::class, 'channels'))->getValue($driver));

    expect($canales)->not->toContain('notifications')
        ->and($canales)->not->toContain('test-messages')
        ->and($canales)->toContain('private-user.{userId}', 'tenant.{tenantId}');
});

test('la autenticación de canales no devuelve el mensaje de la excepción interna', function () {
    $user = User::factory()->create();

    Broadcast::shouldReceive('auth')->andThrow(new RuntimeException('SQLSTATE[08006] host=10.0.0.5 secreto'));

    $request = Request::create('/broadcasting/auth', 'POST', ['channel_name' => 'private-user.1', 'socket_id' => '123.456']);
    $request->setUserResolver(fn () => $user);

    $res = app(BroadcastAuthController::class)->authenticate($request);

    expect($res->getStatusCode())->toBe(403)
        ->and($res->getContent())->not->toContain('SQLSTATE')->not->toContain('10.0.0.5');
});

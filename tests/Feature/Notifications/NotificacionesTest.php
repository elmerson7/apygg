<?php

use App\Contracts\DeviceServiceInterface;
use App\Contracts\NotificationDeliveryServiceInterface;
use App\Contracts\NotificationPreferenceServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\PushTransportInterface;
use App\Contracts\SmsSenderInterface;
use App\Exceptions\BusinessLogicException;
use App\Integrations\Exceptions\PushTokenInvalidError;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\Push\FakePushTransport;
use App\Integrations\Push\FcmPushTransport;
use App\Models\InboxNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserProfile;
use App\Notifications\TypedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function nt(): NotificationServiceInterface
{
    return app(NotificationServiceInterface::class);
}

function ntDevice(User $user, string $token = 'tok-aaaaaaaa-1'): UserDevice
{
    return app(DeviceServiceInterface::class)->register($user, ['token' => $token, 'platform' => 'android']);
}

function ntH(User $u): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($u)];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    config(['queue.default' => 'sync']);
});

test('notify encola un job por destinatario con la notificación tipada', function () {
    Queue::fake();

    nt()->notify($this->user, 'system_announcement', ['title' => 'Hola', 'message' => 'Mundo']);

    Queue::assertPushed(SendQueuedNotifications::class, 1);
});

test('un tipo desconocido se rechaza con 422 de dominio', function () {
    expect(fn () => nt()->notify($this->user, 'no_existe'))->toThrow(BusinessLogicException::class);
});

test('envía por todos los canales del tipo: bandeja, correo y push', function () {
    Mail::fake();
    ntDevice($this->user);

    nt()->notifyNow($this->user, 'security_alert', ['message' => 'Nuevo acceso']);

    expect(InboxNotification::query()->where('user_id', $this->user->id)->value('title'))->not->toBeEmpty()
        ->and(app(PushTransportInterface::class)->sent())->toHaveCount(1)
        ->and(NotificationDelivery::query()->where('status', 'sent')->pluck('channel')->sort()->values()->all())->toBe(['database', 'mail', 'push']);
});

test('el canal sms usa la integración existente y respeta el destino del perfil', function () {
    config(['notifications.types.sms_demo' => ['channels' => ['sms']]]);
    app('translator')->addLines(['notifications.types.sms_demo.title' => 'T', 'notifications.types.sms_demo.body' => 'B'], 'es');
    app('translator')->addLines(['notifications.types.sms_demo.title' => 'T', 'notifications.types.sms_demo.body' => 'B'], 'en');

    nt()->notifyNow($this->user, 'sms_demo');
    expect(NotificationDelivery::query()->value('skipped_reason'))->toBe('no_destination');

    UserProfile::create(['user_id' => $this->user->id, 'phone' => '+34600000009']);
    nt()->notifyNow($this->user->fresh(), 'sms_demo');

    expect(app(SmsSenderInterface::class)->sent())->toHaveCount(1);
});

test('la preferencia de desactivar un canal lo omite y lo deja registrado', function () {
    app(NotificationPreferenceServiceInterface::class)->update($this->user, [['type' => 'system_announcement', 'channel' => 'mail', 'enabled' => false]]);
    Mail::fake();

    nt()->notifyNow($this->user, 'system_announcement', ['title' => 'a', 'message' => 'b']);

    expect(InboxNotification::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('channel', 'mail')->value('skipped_reason'))->toBe('opt_out');
});

test('un tipo obligatorio no se puede desactivar y ignora preferencias guardadas', function () {
    expect(fn () => app(NotificationPreferenceServiceInterface::class)->update($this->user, [['type' => 'security_alert', 'channel' => 'mail', 'enabled' => false]]))
        ->toThrow(BusinessLogicException::class);

    NotificationPreference::create(['user_id' => $this->user->id, 'type' => 'security_alert', 'channel' => 'database', 'enabled' => false]);
    nt()->notifyNow($this->user, 'security_alert', ['message' => 'x']);

    expect(InboxNotification::query()->count())->toBe(1);
});

test('en horas de silencio no se envía push, pero sí la bandeja', function () {
    $this->user->forceFill(['timezone' => 'UTC'])->save();
    ntDevice($this->user);
    $prefs = app(NotificationPreferenceServiceInterface::class);
    $this->travelTo(now()->setTime(23, 30));
    $prefs->update($this->user, [], ['enabled' => true, 'start' => '22:00', 'end' => '07:00']);

    nt()->notifyNow($this->user, 'account_activity', ['message' => 'algo']);

    expect(app(PushTransportInterface::class)->sent())->toBe([])
        ->and(InboxNotification::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('channel', 'push')->value('skipped_reason'))->toBe('quiet_hours');
});

test('las horas de silencio se calculan en la zona horaria del usuario', function () {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    $prefs = app(NotificationPreferenceServiceInterface::class);
    $prefs->update($this->user, [], ['enabled' => true, 'start' => '22:00', 'end' => '07:00']);

    // 14:00 UTC = 23:00 en Tokio (dentro); 02:00 UTC = 11:00 en Tokio (fuera)
    expect($prefs->inQuietHours($this->user, now()->setTime(14, 0)))->toBeTrue()
        ->and($prefs->inQuietHours($this->user, now()->setTime(2, 0)))->toBeFalse();
});

test('un tipo obligatorio ignora las horas de silencio', function () {
    ntDevice($this->user);
    $this->travelTo(now()->setTime(23, 30));
    app(NotificationPreferenceServiceInterface::class)->update($this->user, [], ['enabled' => true, 'start' => '22:00', 'end' => '07:00']);
    Mail::fake();

    nt()->notifyNow($this->user, 'security_alert', ['message' => 'x']);

    expect(app(PushTransportInterface::class)->sent())->toHaveCount(1);
});

test('las horas de silencio activadas exigen inicio y fin', function () {
    expect(fn () => app(NotificationPreferenceServiceInterface::class)->update($this->user, [], ['enabled' => true]))
        ->toThrow(BusinessLogicException::class);
});

test('el reclamo de una entrega es idempotente: la segunda vez no se envía', function () {
    $d = app(NotificationDeliveryServiceInterface::class);
    $id = (string) Str::uuid();

    $first = $d->claim($id, $this->user->id, 'security_alert', 'mail');
    $second = $d->claim($id, $this->user->id, 'security_alert', 'mail');

    expect($first)->not->toBeNull()->and($second)->toBeNull();

    $d->markSent($first);
    expect($d->claim($id, $this->user->id, 'security_alert', 'mail'))->toBeNull();
});

test('una entrega fallida se puede reclamar de nuevo y una abandonada caduca su arrendamiento', function () {
    $d = app(NotificationDeliveryServiceInterface::class);
    $id = (string) Str::uuid();

    $c = $d->claim($id, $this->user->id, 't', 'mail');
    $d->markFailed($c, 'boom');
    $again = $d->claim($id, $this->user->id, 't', 'mail');
    expect($again)->not->toBeNull()->and($again->attempts)->toBe(2);

    expect($d->claim($id, $this->user->id, 't', 'mail'))->toBeNull();
    $this->travel(5)->minutes();
    expect($d->claim($id, $this->user->id, 't', 'mail'))->not->toBeNull();
});

test('el mismo job repetido no duplica la bandeja ni el push', function () {
    ntDevice($this->user);
    Mail::fake();
    $notification = new TypedNotification('security_alert', ['message' => 'x']);
    $notification->id = (string) Str::uuid();

    Notification::sendNow($this->user, $notification);
    Notification::sendNow($this->user, $notification);

    expect(InboxNotification::query()->count())->toBe(1)
        ->and(app(PushTransportInterface::class)->sent())->toHaveCount(1);
});

test('un error reintentable en un canal no impide los demás y se reintenta solo lo fallido', function () {
    ntDevice($this->user);
    Mail::fake();
    app(PushTransportInterface::class)->failNext(TransientIntegrationError::class);
    $notification = new TypedNotification('security_alert', ['message' => 'x']);
    $notification->id = (string) Str::uuid();

    expect(fn () => Notification::sendNow($this->user, $notification))->toThrow(TransientIntegrationError::class);
    expect(InboxNotification::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('channel', 'push')->value('status'))->toBe('failed');

    Notification::sendNow($this->user, $notification); // el reintento

    expect(InboxNotification::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('channel', 'push')->value('status'))->toBe('sent')
        ->and(NotificationDelivery::query()->where('channel', 'database')->value('attempts'))->toBe(1);
});

test('un token que el proveedor rechaza se borra del usuario', function () {
    ntDevice($this->user);
    app(PushTransportInterface::class)->failNext(PushTokenInvalidError::class);
    Mail::fake();

    nt()->notifyNow($this->user, 'account_activity', ['message' => 'x']);

    expect(UserDevice::query()->count())->toBe(0);
});

test('barre las entregas atascadas y purga las antiguas', function () {
    $d = app(NotificationDeliveryServiceInterface::class);
    $stuck = $d->claim((string) Str::uuid(), $this->user->id, 't', 'mail');
    $done = $d->claim((string) Str::uuid(), $this->user->id, 't', 'sms');
    $d->markSent($done);

    $this->travel(20)->minutes();
    $this->artisan('notifications:sweep')->assertSuccessful();
    expect($stuck->fresh()->status)->toBe('failed')->and($done->fresh()->status)->toBe('sent');

    $this->travel(100)->days();
    $this->artisan('notifications:purge')->assertSuccessful();
    expect(NotificationDelivery::query()->count())->toBe(0);
});

test('la purga borra la bandeja leída antigua pero no la no leída', function () {
    nt()->notifyNow($this->user, 'system_announcement', ['title' => 'a', 'message' => 'b']);
    nt()->notifyNow($this->user, 'system_announcement', ['title' => 'c', 'message' => 'd']);
    InboxNotification::query()->first()->forceFill(['read_at' => now()])->save();

    $this->travel(200)->days();
    $this->artisan('notifications:purge')->assertSuccessful();

    expect(InboxNotification::query()->count())->toBe(1)->and(InboxNotification::query()->first()->read_at)->toBeNull();
});

// --- API: bandeja ---

test('la bandeja lista, cuenta no leídas, marca leída, marca todas y borra', function () {
    foreach (['a', 'b', 'c'] as $t) {
        nt()->notifyNow($this->user, 'system_announcement', ['title' => $t, 'message' => 'm']);
    }
    $h = ntH($this->user);

    $this->getJson('/user/notifications', $h)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
    $this->getJson('/user/notifications/unread-count', $h)->assertOk()->assertJsonPath('data.unread', 3);

    $id = InboxNotification::query()->orderBy('id')->first()->id;
    $this->postJson("/user/notifications/{$id}/read", [], $h)->assertOk()->assertJsonPath('data.read', true);
    $this->getJson('/user/notifications?unread=1', $h)->assertOk()->assertJsonCount(2, 'data');

    $this->postJson('/user/notifications/read-all', [], $h)->assertOk()->assertJsonPath('data.marked', 2);
    $this->getJson('/user/notifications/unread-count', $h)->assertJsonPath('data.unread', 0);

    $this->deleteJson("/user/notifications/{$id}", [], $h)->assertOk();
    $this->getJson('/user/notifications', $h)->assertJsonCount(2, 'data');
});

test('la bandeja pagina', function () {
    foreach (range(1, 5) as $i) {
        nt()->notifyNow($this->user, 'system_announcement', ['title' => "t{$i}", 'message' => 'm']);
    }

    $this->getJson('/user/notifications?per_page=2', ntH($this->user))->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 3);
});

test('un usuario no ve ni toca la bandeja de otro: el id ajeno es 404', function () {
    $other = User::factory()->create();
    nt()->notifyNow($other, 'system_announcement', ['title' => 'x', 'message' => 'm']);
    $id = InboxNotification::query()->where('user_id', $other->id)->value('id');
    $h = ntH($this->user);

    $this->getJson('/user/notifications', $h)->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/user/notifications/{$id}/read", [], $h)->assertNotFound();
    $this->deleteJson("/user/notifications/{$id}", [], $h)->assertNotFound();
    $this->postJson('/user/notifications/read-all', [], $h)->assertJsonPath('data.marked', 0);
    expect(InboxNotification::query()->find($id)->read_at)->toBeNull();
});

test('la bandeja exige autenticación', function () {
    $this->getJson('/user/notifications')->assertUnauthorized();
    $this->getJson('/user/devices')->assertUnauthorized();
    $this->getJson('/user/notifications/preferences')->assertUnauthorized();
});

// --- API: preferencias ---

test('las preferencias se leen y se actualizan por la API', function () {
    $h = ntH($this->user);

    $this->putJson('/user/notifications/preferences', [
        'preferences' => [['type' => 'system_announcement', 'channel' => 'mail', 'enabled' => false]],
        'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00'],
    ], $h)->assertOk();

    $data = $this->getJson('/user/notifications/preferences', $h)->assertOk()->json('data');
    $announcement = collect($data['types'])->firstWhere('type', 'system_announcement');

    expect($announcement['channels']['mail'])->toBeFalse()
        ->and($announcement['channels']['database'])->toBeTrue()
        ->and($data['quiet_hours']['enabled'])->toBeTrue()
        ->and($data['quiet_hours']['start'])->toBe('22:00');
});

test('desactivar un tipo obligatorio por la API responde 422', function () {
    $this->putJson('/user/notifications/preferences', [
        'preferences' => [['type' => 'security_alert', 'channel' => 'mail', 'enabled' => false]],
    ], ntH($this->user))->assertStatus(422);
});

test('las preferencias validan el formato de la hora', function () {
    $this->putJson('/user/notifications/preferences', ['quiet_hours' => ['enabled' => true, 'start' => '25:99', 'end' => '07:00']], ntH($this->user))
        ->assertStatus(422);
});

// --- API: dispositivos ---

test('registrar, listar y quitar un dispositivo', function () {
    $h = ntH($this->user);

    $res = $this->postJson('/user/devices', ['token' => 'tok-1234567890', 'platform' => 'ios', 'name' => 'iPhone'], $h)->assertCreated();
    $res->assertJsonMissingPath('data.token');
    $id = $res->json('data.id');

    $this->getJson('/user/devices', $h)->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/user/devices', ['token' => 'tok-1234567890', 'platform' => 'ios'], $h)->assertCreated();
    expect(UserDevice::query()->count())->toBe(1);

    $this->deleteJson("/user/devices/{$id}", [], $h)->assertOk();
    expect(UserDevice::query()->count())->toBe(0);
});

test('un token registrado por otro usuario pasa al último que lo registra', function () {
    $other = User::factory()->create();
    ntDevice($other, 'tok-compartido-1');

    $this->postJson('/user/devices', ['token' => 'tok-compartido-1', 'platform' => 'android'], ntH($this->user))->assertCreated();

    expect(UserDevice::query()->count())->toBe(1)->and(UserDevice::query()->first()->user_id)->toBe($this->user->id);
});

test('no se puede quitar el dispositivo de otro usuario', function () {
    $other = User::factory()->create();
    $device = ntDevice($other);

    $this->deleteJson("/user/devices/{$device->id}", [], ntH($this->user))->assertNotFound();
    expect(UserDevice::query()->count())->toBe(1);
});

test('el registro de dispositivos valida plataforma y respeta el máximo', function () {
    $h = ntH($this->user);
    $this->postJson('/user/devices', ['token' => 'x', 'platform' => 'nokia'], $h)->assertStatus(422);

    config(['notifications.devices.max_per_user' => 1]);
    ntDevice($this->user, 'tok-uno-aaaa');
    $this->postJson('/user/devices', ['token' => 'tok-dos-bbbb', 'platform' => 'ios'], $h)->assertStatus(422);
    $this->postJson('/user/devices', ['token' => 'tok-uno-aaaa', 'platform' => 'ios'], $h)->assertCreated();
});

test('la purga borra los dispositivos sin verse en mucho tiempo', function () {
    ntDevice($this->user);
    $this->travel(200)->days();
    $this->artisan('notifications:purge')->assertSuccessful();

    expect(UserDevice::query()->count())->toBe(0);
});

test('el transporte push falso es el de por defecto y el real se elige por configuración', function () {
    expect(app(PushTransportInterface::class))->toBeInstanceOf(FakePushTransport::class);

    app()->forgetScopedInstances();
    config(['integrations.push.mode' => 'real', 'integrations.push.driver' => 'fcm']);
    expect(app(PushTransportInterface::class))->toBeInstanceOf(FcmPushTransport::class);

    app()->forgetScopedInstances();
    config(['integrations.push.mode' => 'otro']);
    expect(fn () => app(PushTransportInterface::class))->toThrow(InvalidArgumentException::class);
});

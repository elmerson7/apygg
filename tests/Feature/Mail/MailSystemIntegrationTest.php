<?php

use App\Contracts\MailServiceInterface;
use App\Mail\SimpleMail;
use App\Models\Mail\MailAccount;
use App\Models\Mail\MailLog;
use App\Models\Mail\MailProvider;
use App\Models\Mail\MailSetting;
use App\Models\Mail\MailTemplateConfig;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    // El .env del entorno puede fijar otro mailer o una cola real: se aísla aquí.
    config(['mail.fallback' => 'array', 'queue.default' => 'sync']);
});

function cuentaDeEnvio(string $from, array $extra = []): MailAccount
{
    $provider = MailProvider::firstOrCreate(
        ['driver' => 'array'],
        ['name' => 'Array', 'is_active' => true, 'sort_order' => 0],
    );

    return MailAccount::create(array_merge([
        'name' => $from,
        'mail_provider_id' => $provider->id,
        'from_email' => $from,
        'from_name' => 'Remitente',
        'is_active' => true,
        'is_default' => false,
        'priority' => 0,
    ], $extra));
}

function entregados(): array
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all();
}

test('la notificación de reset de contraseña pasa por el registro de correo', function () {
    $cuenta = cuentaDeEnvio('seguridad@ejemplo.com', ['is_default' => true]);
    $user = User::factory()->create();

    $user->notify(new ResetPasswordNotification('tok-secreto', 'https://app.ejemplo.com/reset'));

    $log = MailLog::first();

    expect($log)->not->toBeNull()
        ->and($log->template_key)->toBe('password_reset')
        ->and($log->to_email)->toBe($user->email)
        ->and($log->status)->toBe(MailLog::STATUS_SENT)
        ->and($log->mail_account_id)->toBe($cuenta->id)
        ->and(json_encode($log->metadata))->not->toContain('tok-secreto');

    expect(entregados())->toHaveCount(1)
        ->and(entregados()[0]->getOriginalMessage()->getFrom()[0]->getAddress())->toBe('seguridad@ejemplo.com');
});

test('la bienvenida pasa por el registro de correo con su plantilla', function () {
    cuentaDeEnvio('hola@ejemplo.com', ['is_default' => true]);
    $user = User::factory()->create();

    $user->notify(new WelcomeNotification);

    expect(MailLog::first())
        ->template_key->toBe('welcome')
        ->status->toBe(MailLog::STATUS_SENT);
});

test('las cabeceras internas no llegan al proveedor', function () {
    cuentaDeEnvio('hola@ejemplo.com', ['is_default' => true]);

    User::factory()->create()->notify(new WelcomeNotification);

    $cabeceras = entregados()[0]->getOriginalMessage()->getHeaders();

    expect($cabeceras->has('X-Mail-Account'))->toBeFalse()
        ->and($cabeceras->has('X-Mail-Log'))->toBeFalse()
        ->and($cabeceras->has('X-Mail-Template'))->toBeFalse();
});

test('la pausa global cancela la notificación y deja constancia', function () {
    cuentaDeEnvio('hola@ejemplo.com', ['is_default' => true]);
    MailSetting::setValue(MailSetting::KEY_GLOBAL_PAUSE, true);

    User::factory()->create()->notify(new WelcomeNotification);

    expect(entregados())->toBeEmpty();

    $log = MailLog::first();

    expect($log->status)->toBe(MailLog::STATUS_SKIPPED)
        ->and($log->error_message)->toContain('Pausa global');
});

test('pausar la plantilla corta solo ese correo', function () {
    cuentaDeEnvio('hola@ejemplo.com', ['is_default' => true]);
    MailTemplateConfig::create(['template_key' => 'welcome', 'template_name' => 'Bienvenida', 'is_active' => false]);

    $user = User::factory()->create();
    $user->notify(new WelcomeNotification);
    $user->notify(new ResetPasswordNotification('tok'));

    expect(entregados())->toHaveCount(1)
        ->and(MailLog::where('template_key', 'welcome')->first()->status)->toBe(MailLog::STATUS_SKIPPED)
        ->and(MailLog::where('template_key', 'password_reset')->first()->status)->toBe(MailLog::STATUS_SENT);
});

test('el límite diario se aplica a las notificaciones', function () {
    cuentaDeEnvio('hola@ejemplo.com', ['is_default' => true]);
    MailSetting::setValue(MailSetting::KEY_DAILY_LIMIT, 1);

    $user = User::factory()->create();
    $user->notify(new WelcomeNotification);
    $user->notify(new WelcomeNotification);

    expect(entregados())->toHaveCount(1)
        ->and(MailLog::where('status', MailLog::STATUS_SKIPPED)->count())->toBe(1);
});

test('con dos cuentas usa la que está activa y cambia al cambiar la activa', function () {
    $a = cuentaDeEnvio('a@ejemplo.com', ['is_default' => true]);
    $b = cuentaDeEnvio('b@ejemplo.com', ['priority' => 5]);
    $user = User::factory()->create();

    $user->notify(new WelcomeNotification);

    $a->update(['is_active' => false, 'is_default' => false]);
    $b->update(['is_default' => true]);

    $user->notify(new WelcomeNotification);

    $remitentes = array_map(
        fn ($m) => $m->getOriginalMessage()->getFrom()[0]->getAddress(),
        entregados(),
    );

    expect($remitentes)->toBe(['a@ejemplo.com', 'b@ejemplo.com'])
        ->and(MailLog::orderBy('id')->pluck('mail_account_id')->all())->toBe([$a->id, $b->id]);
});

test('la cuenta asignada a la plantilla manda sobre la predeterminada', function () {
    cuentaDeEnvio('general@ejemplo.com', ['is_default' => true]);
    $seguridad = cuentaDeEnvio('seguridad@ejemplo.com');
    MailTemplateConfig::create([
        'template_key' => 'password_reset',
        'template_name' => 'Reset',
        'mail_account_id' => $seguridad->id,
        'is_active' => true,
    ]);

    User::factory()->create()->notify(new ResetPasswordNotification('tok'));

    expect(entregados()[0]->getOriginalMessage()->getFrom()[0]->getAddress())->toBe('seguridad@ejemplo.com');
});

test('un fallo del proveedor marca el registro como fallido', function () {
    $provider = MailProvider::create(['name' => 'Roto', 'driver' => 'smtp-inexistente', 'is_active' => true, 'sort_order' => 0]);
    MailAccount::create([
        'name' => 'Rota', 'mail_provider_id' => $provider->id, 'from_email' => 'x@ejemplo.com',
        'from_name' => 'X', 'is_active' => true, 'is_default' => true, 'priority' => 0,
    ]);

    expect(fn () => User::factory()->create()->notify(new WelcomeNotification))->toThrow(InvalidArgumentException::class);

    expect(MailLog::first()->status)->toBe(MailLog::STATUS_FAILED);
});

test('con Notification::fake la notificación se despacha sin tocar el sistema de correo', function () {
    Notification::fake();
    $user = User::factory()->create();

    $user->notify(new WelcomeNotification);

    Notification::assertSentTo($user, WelcomeNotification::class);
    expect(MailLog::count())->toBe(0);
});

test('con Mail::fake los Mailables se pueden afirmar sin enviarse', function () {
    Mail::fake();

    app(MailServiceInterface::class)->send('ana@ejemplo.com', 'Hola', 'emails.test');

    Mail::assertQueued(SimpleMail::class);
    expect(MailLog::count())->toBe(0);
});

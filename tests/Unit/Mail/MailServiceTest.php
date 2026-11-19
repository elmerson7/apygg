<?php

use App\Mail\SimpleMail;
use App\Models\Mail\MailAccount;
use App\Models\Mail\MailLog;
use App\Models\Mail\MailSetting;
use App\Models\Mail\MailTemplateConfig;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    // El .env del entorno puede fijar otro mailer o una cola real: se aísla aquí.
    config(['mail.fallback' => 'array', 'queue.default' => 'sync']);

    $this->account = MailAccount::create([
        'name' => 'Principal',
        'from_email' => 'no-reply@ejemplo.com',
        'from_name' => 'Ejemplo',
        'is_active' => true,
        'is_default' => true,
        'priority' => 0,
    ]);
});

/** @return array<string, string> */
function datosVista(): array
{
    return ['app_name' => 'App', 'account_name' => 'X', 'driver' => 'array', 'sent_at' => 'ahora'];
}

/** Mensajes que llegaron al transport real (array) tras pasar por `accounts`. */
function mensajesEntregados(): array
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all();
}

test('encola un Mailable estándar por cada destinatario', function () {
    Mail::fake();

    expect(MailService::send(['a@ejemplo.com', 'b@ejemplo.com'], 'Hola', 'emails.test'))->toBeTrue();

    Mail::assertQueued(SimpleMail::class, 2);
});

test('programa el envío cuando se indica retraso', function () {
    Mail::fake();

    MailService::send('ana@ejemplo.com', 'Hola', 'emails.test', [], null, true, null, [], 3600);

    Mail::assertQueued(SimpleMail::class, 1);
});

test('el envío síncrono registra el correo y lo marca como enviado', function () {
    $result = MailService::send('ana@ejemplo.com', 'Hola', 'emails.test', datosVista(), 'welcome', queue: false);

    expect($result)->toBeTrue();

    $log = MailLog::first();

    expect($log)->not->toBeNull()
        ->and($log->to_email)->toBe('ana@ejemplo.com')
        ->and($log->template_key)->toBe('welcome')
        ->and($log->status)->toBe(MailLog::STATUS_SENT)
        ->and($log->sent_at)->not->toBeNull()
        ->and($log->provider_message_id)->not->toBeNull()
        ->and($log->mail_account_id)->toBe($this->account->id);
});

test('acepta destinatarios con nombre', function () {
    MailService::send(['email' => 'ana@ejemplo.com', 'name' => 'Ana'], 'Hola', 'emails.test', datosVista(), queue: false);

    expect(MailLog::first()->to_name)->toBe('Ana');
});

test('no envía nada con la pausa global activa y lo deja registrado como omitido', function () {
    MailSetting::setValue(MailSetting::KEY_GLOBAL_PAUSE, true);

    expect(MailService::send('ana@ejemplo.com', 'Hola', 'emails.test', datosVista(), null, false))->toBeFalse();

    expect(mensajesEntregados())->toBeEmpty()
        ->and(MailLog::first()->status)->toBe(MailLog::STATUS_SKIPPED);
});

test('no envía si la plantilla está pausada', function () {
    MailTemplateConfig::create([
        'template_key' => 'welcome',
        'template_name' => 'Bienvenida',
        'is_active' => false,
    ]);

    expect(MailService::send('ana@ejemplo.com', 'Hola', 'emails.test', datosVista(), 'welcome', false))->toBeFalse();

    expect(mensajesEntregados())->toBeEmpty();
});

test('respeta el límite diario', function () {
    MailSetting::setValue(MailSetting::KEY_DAILY_LIMIT, 1);
    $datos = datosVista();

    expect(MailService::send('a@ejemplo.com', 'Uno', 'emails.test', $datos, null, false))->toBeTrue()
        ->and(MailService::send('b@ejemplo.com', 'Dos', 'emails.test', $datos, null, false))->toBeFalse();

    expect(mensajesEntregados())->toHaveCount(1);
});

test('sin ninguna cuenta activa usa el mailer de respaldo y deja el registro sin cuenta', function () {
    $this->account->update(['is_active' => false]);

    $ok = MailService::send('ana@ejemplo.com', 'Hola', 'emails.test', datosVista(), null, false);

    expect($ok)->toBeTrue()
        ->and(MailLog::first()->mail_account_id)->toBeNull();
});

test('los datos de la vista no se guardan en el registro', function () {
    MailService::send('ana@ejemplo.com', 'Reset', 'emails.test', datosVista() + [
        'reset_url' => 'https://ejemplo.com/reset?token=secreto',
        'password' => 'temporal123',
    ], null, false);

    $json = json_encode(MailLog::first()->metadata);

    expect($json)->not->toContain('secreto')
        ->and($json)->not->toContain('temporal123');
});

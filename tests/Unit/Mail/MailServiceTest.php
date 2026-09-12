<?php

use App\Jobs\SendMailJob;
use App\Models\Mail\MailAccount;
use App\Models\Mail\MailLog;
use App\Models\Mail\MailSetting;
use App\Models\Mail\MailTemplateConfig;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->account = MailAccount::create([
        'name' => 'Principal',
        'from_email' => 'no-reply@ejemplo.com',
        'from_name' => 'Ejemplo',
        'is_active' => true,
        'is_default' => true,
        'priority' => 0,
    ]);
});

test('encola el envío y registra el correo', function () {
    $result = MailService::send('ana@ejemplo.com', 'Hola', 'emails.welcome', ['name' => 'Ana'], 'welcome');

    expect($result)->toBeTrue();

    Queue::assertPushed(SendMailJob::class, 1);

    $log = MailLog::first();

    expect($log)->not->toBeNull()
        ->and($log->to_email)->toBe('ana@ejemplo.com')
        ->and($log->template_key)->toBe('welcome')
        ->and($log->status)->toBe(MailLog::STATUS_PENDING)
        ->and($log->mail_account_id)->toBe($this->account->id);
});

test('registra un envío por cada destinatario', function () {
    MailService::send(['a@ejemplo.com', 'b@ejemplo.com'], 'Hola', 'emails.welcome');

    Queue::assertPushed(SendMailJob::class, 2);
    expect(MailLog::count())->toBe(2);
});

test('acepta destinatarios con nombre', function () {
    MailService::send(['email' => 'ana@ejemplo.com', 'name' => 'Ana'], 'Hola', 'emails.welcome');

    expect(MailLog::first()->to_name)->toBe('Ana');
});

test('no envía nada con la pausa global activa', function () {
    MailSetting::setValue(MailSetting::KEY_GLOBAL_PAUSE, true);

    expect(MailService::send('ana@ejemplo.com', 'Hola', 'emails.welcome'))->toBeFalse();

    Queue::assertNothingPushed();
    expect(MailLog::count())->toBe(0);
});

test('no envía si la plantilla está pausada', function () {
    MailTemplateConfig::create([
        'template_key' => 'welcome',
        'template_name' => 'Bienvenida',
        'is_active' => false,
    ]);

    expect(MailService::send('ana@ejemplo.com', 'Hola', 'emails.welcome', [], 'welcome'))->toBeFalse();

    Queue::assertNothingPushed();
});

test('respeta el límite diario', function () {
    MailSetting::setValue(MailSetting::KEY_DAILY_LIMIT, 1);

    expect(MailService::send('a@ejemplo.com', 'Uno', 'emails.welcome'))->toBeTrue()
        ->and(MailService::send('b@ejemplo.com', 'Dos', 'emails.welcome'))->toBeFalse();

    Queue::assertPushed(SendMailJob::class, 1);
});

test('un envío programado queda marcado como scheduled', function () {
    MailService::send('ana@ejemplo.com', 'Hola', 'emails.welcome', [], null, true, null, [], 3600);

    expect(MailLog::first()->status)->toBe(MailLog::STATUS_SCHEDULED)
        ->and(MailLog::first()->scheduled_at)->not->toBeNull();
});

test('no envía si no hay ninguna cuenta activa', function () {
    $this->account->update(['is_active' => false]);

    expect(MailService::send('ana@ejemplo.com', 'Hola', 'emails.welcome'))->toBeFalse();

    Queue::assertNothingPushed();
});

test('los datos sensibles no se guardan en el registro', function () {
    MailService::send('ana@ejemplo.com', 'Reset', 'emails.password-reset', [
        'name' => 'Ana',
        'reset_url' => 'https://ejemplo.com/reset?token=secreto',
        'password' => 'temporal123',
        'token' => 'abc',
    ]);

    $metadata = MailLog::first()->metadata;

    expect($metadata)->toHaveKey('name')
        ->and($metadata)->not->toHaveKey('reset_url')
        ->and($metadata)->not->toHaveKey('password')
        ->and($metadata)->not->toHaveKey('token');
});

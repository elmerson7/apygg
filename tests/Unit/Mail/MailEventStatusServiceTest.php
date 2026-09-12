<?php

use App\Models\Mail\MailAccount;
use App\Models\Mail\MailEvent;
use App\Models\Mail\MailLog;
use App\Services\Mail\MailEventStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $account = MailAccount::create([
        'name' => 'Principal',
        'from_email' => 'no-reply@ejemplo.com',
        'from_name' => 'Ejemplo',
        'is_active' => true,
        'is_default' => true,
    ]);

    $this->log = MailLog::create([
        'mail_account_id' => $account->id,
        'to_email' => 'ana@ejemplo.com',
        'subject' => 'Hola',
        'status' => MailLog::STATUS_SENT,
        'provider_message_id' => 're_123',
    ]);

    $this->service = new MailEventStatusService;
});

function addEvent(MailLog $log, string $type, string $messageId, ?string $occurredAt = null): MailEvent
{
    return MailEvent::create([
        'mail_log_id' => $log->id,
        'provider_message_id' => $messageId,
        'provider_event_id' => uniqid('evt_'),
        'type' => $type,
        'payload' => ['type' => $type],
        'occurred_at' => $occurredAt,
    ]);
}

test('un rebote marca el envío como bounced', function () {
    addEvent($this->log, 'email.bounced', 're_123', now()->toDateTimeString());

    expect($this->service->syncStatus($this->log))->toBeTrue()
        ->and($this->log->fresh()->status)->toBe(MailLog::STATUS_BOUNCED);
});

test('una entrega marca el envío como delivered', function () {
    addEvent($this->log, 'email.delivered', 're_123', now()->toDateTimeString());

    $this->service->syncStatus($this->log);

    expect($this->log->fresh()->status)->toBe(MailLog::STATUS_DELIVERED);
});

test('gana el evento más reciente por fecha de ocurrencia, no el último insertado', function () {
    addEvent($this->log, 'email.bounced', 're_123', now()->subMinutes(5)->toDateTimeString());
    addEvent($this->log, 'email.delivered', 're_123', now()->toDateTimeString());
    // Llega tarde un evento antiguo: no debe cambiar el estado
    addEvent($this->log, 'email.sent', 're_123', now()->subMinutes(10)->toDateTimeString());

    $this->service->syncStatus($this->log);

    expect($this->log->fresh()->status)->toBe(MailLog::STATUS_DELIVERED);
});

test('los eventos de un envío anterior no afectan al vigente', function () {
    addEvent($this->log, 'email.bounced', 're_viejo', now()->toDateTimeString());

    expect($this->service->syncStatus($this->log))->toBeFalse()
        ->and($this->log->fresh()->status)->toBe(MailLog::STATUS_SENT);
});

test('un tipo de evento desconocido no cambia el estado', function () {
    addEvent($this->log, 'email.algo_nuevo', 're_123', now()->toDateTimeString());

    expect($this->service->syncStatus($this->log))->toBeFalse()
        ->and($this->log->fresh()->status)->toBe(MailLog::STATUS_SENT);
});

test('mapType traduce los tipos conocidos', function () {
    expect($this->service->mapType('email.delivered'))->toBe(MailLog::STATUS_DELIVERED)
        ->and($this->service->mapType('email.complained'))->toBe(MailLog::STATUS_BOUNCED)
        ->and($this->service->mapType('email.attempt_failed'))->toBe(MailLog::STATUS_FAILED)
        ->and($this->service->mapType('inexistente'))->toBeNull();
});

<?php

use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\Exceptions\RateLimitedError;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\Sms\FakeSmsSender;
use App\Integrations\Sms\SmsMessage;

test('el SMS falso es determinista y registra cada llamada', function () {
    $fake = new FakeSmsSender;

    $a = $fake->send(new SmsMessage('+34 600 000 001', 'Hola'));
    $b = $fake->send(new SmsMessage('+34600000002', 'Adiós'));

    expect($a->id)->toBe('fake-sms-1')->and($b->id)->toBe('fake-sms-2')
        ->and($fake->calls('send'))->toHaveCount(2)
        ->and($fake->sent()[0]->to)->toBe('+34600000001')
        ->and($fake->sent()[1]->body)->toBe('Adiós');
});

test('la misma clave de idempotencia no duplica el envío en el resultado', function () {
    $fake = new FakeSmsSender;

    $a = $fake->send(new SmsMessage('+34600000001', 'Hola', 'clave-1'));
    $b = $fake->send(new SmsMessage('+34600000001', 'Hola', 'clave-1'));

    expect($b->id)->toBe($a->id);
});

test('inyecta fallos por tipo, en orden, y luego vuelve a funcionar', function () {
    $fake = new FakeSmsSender;
    $fake->failNext(TransientIntegrationError::class)->failNext(RateLimitedError::class);
    $msg = new SmsMessage('+34600000001', 'Hola');

    expect(fn () => $fake->send($msg))->toThrow(TransientIntegrationError::class);
    expect(fn () => $fake->send($msg))->toThrow(RateLimitedError::class);
    expect($fake->send($msg)->id)->toStartWith('fake-sms-')
        ->and($fake->calls('send'))->toHaveCount(3);
});

test('failNext con times repite el fallo y rechaza tipos que no son de integración', function () {
    $fake = new FakeSmsSender;
    $fake->failNext(times: 2);
    $msg = new SmsMessage('+34600000001', 'Hola');

    expect(fn () => $fake->send($msg))->toThrow(PermanentIntegrationError::class);
    expect(fn () => $fake->send($msg))->toThrow(PermanentIntegrationError::class);
    expect($fake->send($msg))->not->toBeNull();
    expect(fn () => $fake->failNext(RuntimeException::class))->toThrow(InvalidArgumentException::class);
});

test('resetFake limpia llamadas y fallos pendientes', function () {
    $fake = new FakeSmsSender;
    $fake->failNext();
    $fake->resetFake();
    $fake->send(new SmsMessage('+34600000001', 'Hola'));

    expect($fake->calls())->toHaveCount(1);
});

test('rechaza un número que no es E.164 y un texto vacío', function () {
    expect(fn () => new SmsMessage('600000001', 'Hola'))->toThrow(PermanentIntegrationError::class)
        ->and(fn () => new SmsMessage('+34600000001', '  '))->toThrow(PermanentIntegrationError::class);
});

test('enmascara el número para los logs', function () {
    expect((new SmsMessage('+34600000001', 'x'))->maskedTo())->toBe('+34******001');
});

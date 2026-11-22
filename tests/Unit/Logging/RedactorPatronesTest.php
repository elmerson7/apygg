<?php

use App\Helpers\Redactor;
use App\Logging\RedactingProcessor;
use App\Logging\RedactLogs;
use App\Logging\SentryScrubber;
use App\Models\Logs\ActivityLog;
use App\Models\Logs\ApiLog;
use App\Models\Logs\SecurityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;

uses(RefreshDatabase::class);

test('enmascara emails conservando una pista', function () {
    expect(Redactor::redactString('Hola juan@dominio.com fin'))->toBe('Hola j***@d***.com fin');
});

test('enmascara tarjetas válidas por Luhn y deja pasar números que no lo son', function () {
    expect(Redactor::redactString('tarjeta 4111 1111 1111 1111 ok'))->toBe('tarjeta ****1111 ok')
        ->and(Redactor::redactString('pedido 4111111111111112'))->toBe('pedido 4111111111111112');
});

test('no confunde un UUID ni un id hexadecimal con una tarjeta', function () {
    // El tramo 575-7772-4917-83 de este UUID pasa Luhn: antes se enmascaraba al azar
    $uuid = '169ec575-7772-4917-83f4-65904b2466a4';

    expect(Redactor::redactString("token_id={$uuid}"))->toBe("token_id={$uuid}")
        ->and(Redactor::redactString('12345678-1234-1234-1234-123456789012'))->toBe('12345678-1234-1234-1234-123456789012')
        ->and(Redactor::redactString('ref a4111111111111111b'))->toBe('ref a4111111111111111b')
        ->and(Redactor::redactString('pago con 4111-1111-1111-1111.'))->toBe('pago con ****1111.')
        ->and(Redactor::redactString('(4111111111111111)'))->toBe('(****1111)');
});

test('enmascara teléfonos internacionales y JWT y Bearer', function () {
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.firmaabc123';

    expect(Redactor::redactString('llama +34 600 123 456'))->toBe('llama ****3456')
        ->and(Redactor::redactString("t=$jwt"))->toBe('t=[REDACTED]')
        ->and(Redactor::redactString('Authorization: Bearer abc.def-123'))->toBe('Authorization: Bearer [REDACTED]');
});

test('redacta claves anidadas y patrones en valores', function () {
    $out = Redactor::redact(['a' => ['b' => ['password' => 'x', 'nota' => 'mail a@b.com']]]);

    expect($out['a']['b']['password'])->toBe('[REDACTED]')
        ->and($out['a']['b']['nota'])->toBe('mail a***@b***.com');
});

test('el modo remove elimina claves y sustituye patrones', function () {
    config(['logging.redact.mode' => 'remove']);
    $out = Redactor::redact(['password' => 'x', 'nota' => 'a@b.com', 'ok' => 1]);

    expect($out)->toBe(['nota' => '[REDACTED]', 'ok' => 1]);
});

test('respeta el límite de profundidad y de longitud', function () {
    config(['logging.redact.max_depth' => 2, 'logging.redact.max_string' => 5]);
    $out = Redactor::redact(['a' => ['b' => ['c' => 1]], 's' => 'abcdefghij']);

    expect($out['a']['b'])->toBe('[MAX_DEPTH]')
        ->and($out['s'])->toStartWith('abcde')->toContain('truncated');
});

test('el processor de Monolog redacta mensaje, contexto y extra', function () {
    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'login de a@b.com',
        ['password' => 'secreto', 'x' => ['token' => 't']], ['jwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.sig12345']);

    $out = (new RedactingProcessor)($record);

    expect($out->message)->toBe('login de a***@b***.com')
        ->and($out->context['password'])->toBe('[REDACTED]')
        ->and($out->context['x']['token'])->toBe('[REDACTED]')
        ->and($out->extra['jwt'])->toBe('[REDACTED]');
});

test('un log por el canal single no deja contraseña ni token en el archivo', function () {
    $path = storage_path('logs/redact-test-'.uniqid().'.log');
    config(['logging.channels.redact_test' => [
        'driver' => 'monolog', 'handler' => StreamHandler::class,
        'handler_with' => ['stream' => $path], 'tap' => [RedactLogs::class],
    ]]);

    Log::channel('redact_test')->info('intento de a@b.com', ['password' => 'PlainPass123!', 'access_token' => 'TOKEN-XYZ']);

    $content = (string) file_get_contents($path);
    @unlink($path);
    expect($content)->not->toContain('PlainPass123!')->not->toContain('TOKEN-XYZ')->not->toContain('a@b.com');
});

test('el before_send de Sentry redacta request, usuario, extra y excepciones', function () {
    $event = Event::createEvent();
    $event->setRequest(['data' => ['password' => 'PlainPass123!'], 'headers' => ['Authorization' => 'Bearer abc'], 'cookies' => ['s' => 'v']]);
    $event->setUser(new UserDataBag('42', 'a@b.com', '1.2.3.4', 'juan'));
    $event->setExtra(['email_enviado' => 'a@b.com', 'api_key' => 'k']);
    $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'x', 'user a@b.com', ['token' => 't'])]);
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('fallo con a@b.com'))]);

    $out = SentryScrubber::beforeSend($event);

    expect($out->getRequest()['data']['password'])->toBe('[REDACTED]')
        ->and($out->getRequest()['headers']['Authorization'])->toBe('[REDACTED]')
        ->and($out->getRequest()['cookies'])->toBe('[REDACTED]')
        ->and($out->getUser()?->getId())->toBe('42')
        ->and($out->getUser()?->getEmail())->toBeNull()
        ->and($out->getUser()?->getIpAddress())->toBeNull()
        ->and($out->getExtra()['api_key'])->toBe('[REDACTED]')
        ->and($out->getExtra()['email_enviado'])->toBe('a***@b***.com')
        ->and($out->getBreadcrumbs()[0]->getMessage())->toBe('user a***@b***.com')
        ->and($out->getBreadcrumbs()[0]->getMetadata()['token'])->toBe('[REDACTED]')
        ->and($out->getExceptions()[0]->getValue())->toBe('fallo con a***@b***.com');
});

test('el before_breadcrumb de Sentry redacta mensaje y metadatos', function () {
    $b = SentryScrubber::beforeBreadcrumb(new Breadcrumb('info', 'default', 'x', 'a@b.com', ['password' => 'p']));

    expect($b->getMessage())->toBe('a***@b***.com')->and($b->getMetadata()['password'])->toBe('[REDACTED]');
});

test('un login fallido y un registro no dejan contraseña ni token en logs de BD', function () {
    $this->postJson('/auth/login', ['email' => 'nadie@dominio.com', 'password' => 'PlainPass123!']);
    $this->postJson('/auth/register', ['name' => 'N', 'email' => 'nuevo@dominio.com', 'password' => 'PlainPass123!', 'password_confirmation' => 'PlainPass123!']);

    $all = json_encode([
        ApiLog::all()->toArray(),
        SecurityLog::all()->toArray(),
        ActivityLog::all()->toArray(),
    ]);

    expect($all)->not->toContain('PlainPass123!')->not->toContain('nadie@dominio.com');
});

<?php

use App\Contracts\NotificationServiceInterface;
use App\Contracts\SmsSenderInterface;
use App\Integrations\Exceptions\InvalidOutputError;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\Exceptions\QuotaExceededError;
use App\Integrations\Exceptions\RateLimitedError;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\Sms\FakeSmsSender;
use App\Integrations\Sms\HttpSmsSender;
use App\Integrations\Sms\LogSmsSender;
use App\Integrations\Sms\SmsMessage;
use App\Jobs\SendSmsJob;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Sin esperas reales en los reintentos
    config(['integrations.defaults.retry.base_delay_ms' => 1, 'integrations.defaults.retry.max_delay_ms' => 2]);
});

function resolveSms(): SmsSenderInterface
{
    app()->forgetInstance(SmsSenderInterface::class);

    return app(SmsSenderInterface::class);
}

test('por defecto (y en tests) el SMS es la versión falsa', function () {
    expect(resolveSms())->toBeInstanceOf(FakeSmsSender::class);
});

test('SMS_MODE real con driver http elige el cliente HTTP', function () {
    config(['integrations.sms.mode' => 'real', 'integrations.sms.driver' => 'http']);

    expect(resolveSms())->toBeInstanceOf(HttpSmsSender::class);
});

test('SMS_MODE real con driver log elige el que solo registra', function () {
    config(['integrations.sms.mode' => 'real', 'integrations.sms.driver' => 'log']);

    expect(resolveSms())->toBeInstanceOf(LogSmsSender::class);
});

test('un modo desconocido falla claro al resolver, no a mitad de una petición', function () {
    config(['integrations.sms.mode' => 'produccion']);
    app()->forgetInstance(SmsSenderInterface::class);

    expect(fn () => app(SmsSenderInterface::class))->toThrow(InvalidArgumentException::class, 'real o fake');
});

test('el binding es scoped: dentro de una misma petición es la misma instancia y no hay estado compartido entre ámbitos', function () {
    $a = app(SmsSenderInterface::class);
    expect(app(SmsSenderInterface::class))->toBe($a);

    $a->send(new SmsMessage('+34600000001', 'Hola'));
    app()->forgetScopedInstances();

    expect(app(SmsSenderInterface::class)->calls())->toBe([]);
});

test('el driver log no escribe el texto ni el número completo', function () {
    config(['integrations.sms.mode' => 'real', 'integrations.sms.driver' => 'log']);
    Log::spy();

    resolveSms()->send(new SmsMessage('+34600000001', 'Tu código es 123456'));

    Log::shouldHaveReceived('info')->withArgs(function (string $msg, array $ctx) {
        return $ctx['to'] === '+34******001' && ! str_contains(json_encode($ctx), '123456');
    });
});

describe('cliente HTTP genérico', function () {
    beforeEach(function () {
        config([
            'integrations.sms.mode' => 'real', 'integrations.sms.driver' => 'http',
            'integrations.sms.from' => 'APYGG',
            'integrations.sms.http.url' => 'https://sms.example.test/send',
            'integrations.sms.http.token' => 'secreto-token',
            'integrations.sms.http.extra' => ['account' => 'acme'],
        ]);
    });

    test('envía el JSON configurado con token, Idempotency-Key y devuelve el id del proveedor', function () {
        Http::fake(['sms.example.test/*' => Http::response(['id' => 'SM123'], 201)]);

        $result = resolveSms()->send(new SmsMessage('+34600000001', 'Hola', 'clave-9'));

        expect($result->id)->toBe('SM123');
        Http::assertSent(fn ($r) => $r->url() === 'https://sms.example.test/send'
            && $r['to'] === '+34600000001' && $r['body'] === 'Hola' && $r['from'] === 'APYGG' && $r['account'] === 'acme'
            && $r->hasHeader('Authorization', 'Bearer secreto-token')
            && $r->hasHeader('Idempotency-Key', 'clave-9'));
    });

    test('traduce cada resultado HTTP al error tipado que corresponde', function (int $status, string $class) {
        Http::fake(['*' => Http::response(['error' => 'x'], $status, ['Retry-After' => '7'])]);

        $thrown = null;

        try {
            resolveSms()->send(new SmsMessage('+34600000001', 'Hola'));
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf($class);
        if ($thrown instanceof RateLimitedError) {
            expect($thrown->retryAfter())->toBe(7);
        }
    })->with([
        [500, TransientIntegrationError::class],
        [503, TransientIntegrationError::class],
        [408, TransientIntegrationError::class],
        [429, RateLimitedError::class],
        [402, QuotaExceededError::class],
        [400, PermanentIntegrationError::class],
        [401, PermanentIntegrationError::class],
        [404, PermanentIntegrationError::class],
    ]);

    test('un timeout o corte de conexión es transitorio', function () {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        expect(fn () => resolveSms()->send(new SmsMessage('+34600000001', 'Hola')))->toThrow(TransientIntegrationError::class);
    });

    test('una respuesta 200 sin id es una salida inválida (no se reintenta)', function () {
        Http::fake(['*' => Http::response(['ok' => true])]);

        expect(fn () => resolveSms()->send(new SmsMessage('+34600000001', 'Hola')))->toThrow(InvalidOutputError::class);
    });

    test('sin URL configurada falla como permanente, sin llamar a la red', function () {
        config(['integrations.sms.http.url' => null]);
        Http::fake();

        expect(fn () => resolveSms()->send(new SmsMessage('+34600000001', 'Hola')))->toThrow(PermanentIntegrationError::class);
        Http::assertNothingSent();
    });

    test('el cliente lleva timeout explícito', function () {
        Http::fake(['*' => Http::response(['id' => '1'])]);
        resolveSms()->send(new SmsMessage('+34600000001', 'Hola'));

        Http::assertSent(fn ($r) => ($r->toPsrRequest()->getHeaderLine('Accept')) === 'application/json');
        expect(config('integrations.defaults.timeout'))->toBeGreaterThan(0);
    });
});

describe('cola y notificaciones', function () {
    test('el job envía con la misma clave de idempotencia en cada intento', function () {
        $fake = app(SmsSenderInterface::class);
        $job = new SendSmsJob('+34600000001', 'Hola', 'clave-fija');

        app()->call([$job, 'process']);

        expect($fake->sent()[0]->idempotencyKey)->toBe('clave-fija');
    });

    test('el job libera para reintentar un error transitorio y no lo marca como fallido', function () {
        $fake = app(SmsSenderInterface::class)->failNext(TransientIntegrationError::class);
        $job = new SendSmsJob('+34600000001', 'Hola', 'k');
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(1);
        $queueJob->shouldReceive('fail')->never();
        $job->setJob($queueJob);

        $job->process($fake);
    });

    test('el job respeta retry_after del rate limit', function () {
        $fake = app(SmsSenderInterface::class)->failNext(RateLimitedError::class);
        $job = new SendSmsJob('+34600000001', 'Hola', 'k');
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once();
        $job->setJob($queueJob);

        $job->process($fake);
    });

    test('el job falla ya, sin reintentar, un error permanente', function () {
        $fake = app(SmsSenderInterface::class)->failNext(PermanentIntegrationError::class);
        $job = new SendSmsJob('+34600000001', 'Hola', 'k');
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('fail')->once();
        $queueJob->shouldReceive('release')->never();
        $queueJob->shouldReceive('isDeleted')->andReturn(false);
        $queueJob->shouldReceive('delete')->zeroOrMoreTimes();
        $job->setJob($queueJob);

        $job->process($fake);
    });

    test('NotificationService::sendSms encola un job y, sin cola, envía con la integración', function () {
        $service = app(NotificationServiceInterface::class);

        Queue::fake();
        expect($service->sendSms('+34600000001', 'Hola'))->toBeTrue();
        Queue::assertPushed(SendSmsJob::class);

        $fake = app(SmsSenderInterface::class);
        expect($service->sendSms('+34600000001', 'Hola', false))->toBeTrue()
            ->and($fake->sent())->toHaveCount(1);
    });

    test('sendSms sin cola reintenta el error transitorio y devuelve false con uno permanente', function () {
        $fake = app(SmsSenderInterface::class);
        $service = app(NotificationServiceInterface::class);

        $fake->failNext(TransientIntegrationError::class);
        expect($service->sendSms('+34600000001', 'Hola', false))->toBeTrue()
            ->and($fake->calls('send'))->toHaveCount(2);

        $fake->resetFake();
        $fake->failNext(PermanentIntegrationError::class);
        expect($service->sendSms('+34600000001', 'Hola', false))->toBeFalse()
            ->and($fake->calls('send'))->toHaveCount(1);
    });

    test('el canal sms de Notifications envía al teléfono del perfil', function () {
        $user = User::factory()->create();
        UserProfile::create(['user_id' => $user->id, 'phone' => '+34600000009']);
        $fake = app(SmsSenderInterface::class);

        $user->notifyNow(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['sms'];
            }

            public function toSms(object $notifiable): string
            {
                return 'Tu pedido salió';
            }
        });

        expect($fake->sent())->toHaveCount(1)
            ->and($fake->sent()[0]->to)->toBe('+34600000009')
            ->and($fake->sent()[0]->body)->toBe('Tu pedido salió');
    });

    test('el canal sms no envía si el usuario no tiene teléfono', function () {
        $user = User::factory()->create();
        $fake = app(SmsSenderInterface::class);

        $user->notifyNow(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['sms'];
            }

            public function toSms(object $notifiable): string
            {
                return 'x';
            }
        });

        expect($fake->calls())->toBe([]);
    });

    test('el canal sms reintenta transitorios pero deja subir los permanentes', function () {
        $fake = app(SmsSenderInterface::class);
        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['sms'];
            }

            public function toSms(object $notifiable): string
            {
                return 'x';
            }
        };
        $route = Illuminate\Support\Facades\Notification::route('sms', '+34600000001');

        $fake->failNext(TransientIntegrationError::class);
        $route->notifyNow($notification);
        expect($fake->calls('send'))->toHaveCount(2);

        $fake->failNext(PermanentIntegrationError::class);
        expect(fn () => $route->notifyNow($notification))->toThrow(PermanentIntegrationError::class);
    });
});

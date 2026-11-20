<?php

use App\Integrations\Exceptions\InvalidOutputError;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\Exceptions\QuotaExceededError;
use App\Integrations\Exceptions\RateLimitedError;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\RetryPolicy;

function retryPolicyFor(array &$sleeps, int $max = 3): RetryPolicy
{
    return new RetryPolicy($max, 100, 1000, 5000, function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    });
}

test('reintenta un error transitorio y devuelve el resultado cuando por fin funciona', function () {
    $sleeps = [];
    $calls = 0;

    $result = retryPolicyFor($sleeps)->run(function (int $attempt) use (&$calls) {
        $calls++;
        if ($attempt < 3) {
            throw new TransientIntegrationError('caído');
        }

        return 'ok';
    });

    expect($result)->toBe('ok')->and($calls)->toBe(3)->and($sleeps)->toHaveCount(2);
});

test('reintenta el rate limit y respeta retry_after por encima del backoff', function () {
    $sleeps = [];

    retryPolicyFor($sleeps)->run(function (int $attempt) {
        if ($attempt === 1) {
            throw new RateLimitedError('429', 'x', 3);
        }

        return true;
    });

    expect($sleeps)->toBe([3000]);
});

test('limita retry_after al máximo configurado', function () {
    $sleeps = [];

    retryPolicyFor($sleeps)->run(function (int $attempt) {
        if ($attempt === 1) {
            throw new RateLimitedError('429', 'x', 3600);
        }

        return true;
    });

    expect($sleeps)->toBe([5000]);
});

test('el backoff crece con el intento y lleva jitter dentro de su rango', function () {
    $sleeps = [];

    try {
        retryPolicyFor($sleeps, 4)->run(fn () => throw new TransientIntegrationError('x'));
    } catch (TransientIntegrationError) {
    }

    expect($sleeps)->toHaveCount(3)
        ->and($sleeps[0])->toBeBetween(50, 100)
        ->and($sleeps[1])->toBeBetween(100, 200)
        ->and($sleeps[2])->toBeBetween(200, 400);
});

test('se rinde tras el máximo de intentos y relanza el último error', function () {
    $sleeps = [];
    $calls = 0;

    expect(function () use (&$sleeps, &$calls) {
        retryPolicyFor($sleeps, 3)->run(function () use (&$calls) {
            $calls++;

            throw new TransientIntegrationError('siempre');
        });
    })->toThrow(TransientIntegrationError::class, 'siempre');

    expect($calls)->toBe(3);
});

test('no reintenta errores permanentes, de cuota ni de salida inválida', function (string $class) {
    $sleeps = [];
    $calls = 0;

    expect(function () use (&$sleeps, &$calls, $class) {
        retryPolicyFor($sleeps)->run(function () use (&$calls, $class) {
            $calls++;

            throw new $class('no');
        });
    })->toThrow($class);

    expect($calls)->toBe(1)->and($sleeps)->toBe([]);
})->with([PermanentIntegrationError::class, QuotaExceededError::class, InvalidOutputError::class]);

test('no toca excepciones que no son de integración', function () {
    $sleeps = [];
    $calls = 0;

    expect(function () use (&$sleeps, &$calls) {
        retryPolicyFor($sleeps)->run(function () use (&$calls) {
            $calls++;

            throw new RuntimeException('bug');
        });
    })->toThrow(RuntimeException::class);

    expect($calls)->toBe(1);
});

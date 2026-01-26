<?php

use App\Models\Logs\SecurityLog;
use App\Models\User;
use App\Services\Logging\AuthLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

test('puede registrar login exitoso', function () {
    $user = User::factory()->create();
    $ipAddress = '192.168.1.1';
    $userAgent = 'Mozilla/5.0';

    $log = AuthLogger::logLoginSuccess($user, $ipAddress, $userAgent);

    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe(SecurityLog::EVENT_LOGIN_SUCCESS)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->ip_address)->toBe($ipAddress)
        ->and($log->user_agent)->toBe($userAgent)
        ->and($log->details)->toHaveKey('login_at');
});

test('usa IP y user agent del request por defecto', function () {
    $user = User::factory()->create();

    $log = AuthLogger::logLoginSuccess($user);

    expect($log->ip_address)->not->toBeNull()
        ->and($log->user_agent)->not->toBeNull();
});

test('puede registrar intento de login fallido', function () {
    $email = 'test@example.com';
    $ipAddress = '192.168.1.1';
    $reason = 'Invalid credentials';

    $log = AuthLogger::logLoginFailure($email, $ipAddress, null, $reason);

    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe(SecurityLog::EVENT_LOGIN_FAILURE)
        ->and($log->user_id)->toBeNull()
        ->and($log->ip_address)->toBe($ipAddress)
        ->and($log->details)->toHaveKey('email')
        ->and($log->details)->toHaveKey('reason')
        ->and($log->details['email'])->toBe($email)
        ->and($log->details['reason'])->toBe($reason);
});

test('incrementa contador de intentos fallidos', function () {
    $email = 'test@example.com';
    $ipAddress = '192.168.1.1';

    AuthLogger::logLoginFailure($email, $ipAddress);

    $attempts = AuthLogger::getFailedAttempts($ipAddress, $email);

    expect($attempts)->toBe(1);
});

test('detecta actividad sospechosa después de múltiples fallos', function () {
    $email = 'test@example.com';
    $ipAddress = '192.168.1.1';

    // Realizar 5 intentos fallidos (límite por defecto)
    for ($i = 0; $i < 5; $i++) {
        AuthLogger::logLoginFailure($email, $ipAddress);
    }

    $hasSuspicious = AuthLogger::hasSuspiciousActivity($ipAddress, $email);

    expect($hasSuspicious)->toBeTrue();

    // Verificar que se creó un log de actividad sospechosa
    $suspiciousLog = SecurityLog::where('event_type', SecurityLog::EVENT_SUSPICIOUS_ACTIVITY)
        ->where('ip_address', $ipAddress)
        ->first();

    expect($suspiciousLog)->not->toBeNull()
        ->and($suspiciousLog->details)->toHaveKey('failed_attempts')
        ->and($suspiciousLog->details['failed_attempts'])->toBeGreaterThanOrEqual(5);
});

test('puede registrar cambio de contraseña', function () {
    $user = User::factory()->create();
    $ipAddress = '192.168.1.1';

    $log = AuthLogger::logPasswordChanged($user, $ipAddress);

    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe(SecurityLog::EVENT_PASSWORD_CHANGED)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->ip_address)->toBe($ipAddress)
        ->and($log->details)->toHaveKey('changed_at');
});

test('puede registrar revocación de token', function () {
    $user = User::factory()->create();
    $tokenId = \Illuminate\Support\Str::uuid()->toString();
    $ipAddress = '192.168.1.1';

    $log = AuthLogger::logTokenRevoked($user, $tokenId, $ipAddress);

    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe(SecurityLog::EVENT_TOKEN_REVOKED)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->details)->toHaveKey('token_id')
        ->and($log->details['token_id'])->toBe($tokenId);
});

test('puede limpiar contador de intentos fallidos', function () {
    $email = 'test@example.com';
    $ipAddress = '192.168.1.1';

    AuthLogger::logLoginFailure($email, $ipAddress);
    expect(AuthLogger::getFailedAttempts($ipAddress, $email))->toBe(1);

    AuthLogger::clearFailedAttempts($ipAddress, $email);

    expect(AuthLogger::getFailedAttempts($ipAddress, $email))->toBe(0);
});

test('no detecta actividad sospechosa con pocos intentos fallidos', function () {
    $email = 'test@example.com';
    $ipAddress = '192.168.1.1';

    // Realizar solo 2 intentos fallidos
    AuthLogger::logLoginFailure($email, $ipAddress);
    AuthLogger::logLoginFailure($email, $ipAddress);

    $hasSuspicious = AuthLogger::hasSuspiciousActivity($ipAddress, $email);

    expect($hasSuspicious)->toBeFalse();
});

test('contador de intentos fallidos expira después del TTL', function () {
    $email = 'test@example.com';
    $ipAddress = '192.168.1.1';

    AuthLogger::logLoginFailure($email, $ipAddress);

    // Simular expiración del cache
    Cache::flush();

    $attempts = AuthLogger::getFailedAttempts($ipAddress, $email);

    expect($attempts)->toBe(0);
});

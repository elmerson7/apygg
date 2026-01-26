<?php

use App\Models\Logs\SecurityLog;
use App\Models\User;
use App\Services\Logging\SecurityLogger;
use Illuminate\Http\Request;

test('puede registrar permiso denegado', function () {
    $user = User::factory()->create();
    $permission = 'users.delete';
    $resource = 'users';
    $request = Request::create('/users/123', 'DELETE');

    $log = SecurityLogger::logPermissionDenied($user, $permission, $resource, $request);

    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe(SecurityLog::EVENT_PERMISSION_DENIED)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->details)->toHaveKey('permission')
        ->and($log->details)->toHaveKey('resource')
        ->and($log->details)->toHaveKey('method')
        ->and($log->details['permission'])->toBe($permission)
        ->and($log->details['resource'])->toBe($resource)
        ->and($log->details['method'])->toBe('DELETE');
});

test('puede registrar permiso denegado sin usuario', function () {
    $permission = 'users.read';
    $request = Request::create('/users', 'GET');

    $log = SecurityLogger::logPermissionDenied(null, $permission, null, $request);

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBeNull()
        ->and($log->event_type)->toBe(SecurityLog::EVENT_PERMISSION_DENIED);
});

test('usa request actual por defecto', function () {
    $user = User::factory()->create();
    $permission = 'users.create';

    $log = SecurityLogger::logPermissionDenied($user, $permission);

    expect($log)->not->toBeNull()
        ->and($log->ip_address)->not->toBeNull();
});

test('puede registrar actividad sospechosa', function () {
    $user = User::factory()->create();
    $description = 'Multiple failed login attempts';
    $details = ['attempts' => 10];
    $request = Request::create('/login', 'POST');

    $log = SecurityLogger::logSuspiciousActivity($description, $user, $details, $request);

    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe(SecurityLog::EVENT_SUSPICIOUS_ACTIVITY)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->details)->toHaveKey('description')
        ->and($log->details)->toHaveKey('attempts')
        ->and($log->details['description'])->toBe($description)
        ->and($log->details['attempts'])->toBe(10);
});

test('puede registrar actividad sospechosa sin usuario', function () {
    $description = 'Suspicious IP activity';
    $request = Request::create('/users', 'GET');

    $log = SecurityLogger::logSuspiciousActivity($description, null, [], $request);

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBeNull();
});

test('puede registrar bloqueo de cuenta', function () {
    $user = User::factory()->create();
    $blockedBy = User::factory()->create();
    $reason = 'Multiple security violations';

    $log = SecurityLogger::logAccountLocked($user, $reason, $blockedBy);

    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe(SecurityLog::EVENT_ACCOUNT_LOCKED)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->details)->toHaveKey('reason')
        ->and($log->details)->toHaveKey('locked_by')
        ->and($log->details['reason'])->toBe($reason)
        ->and($log->details['locked_by'])->toBe($blockedBy->id);
});

test('puede registrar bloqueo de cuenta sin usuario que bloquea', function () {
    $user = User::factory()->create();
    $reason = 'Automated security lock';

    $log = SecurityLogger::logAccountLocked($user, $reason);

    expect($log)->not->toBeNull()
        ->and($log->details['locked_by'])->toBeNull();
});

test('puede registrar desbloqueo de cuenta', function () {
    $user = User::factory()->create();
    $unlockedBy = User::factory()->create();

    $log = SecurityLogger::logAccountUnlocked($user, $unlockedBy);

    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe(SecurityLog::EVENT_ACCOUNT_UNLOCKED)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->details)->toHaveKey('unlocked_by')
        ->and($log->details['unlocked_by'])->toBe($unlockedBy->id);
});

test('puede registrar evento de seguridad personalizado', function () {
    $user = User::factory()->create();
    $eventType = SecurityLog::EVENT_TOKEN_REVOKED;
    $details = ['token_id' => 'abc123'];
    $request = Request::create('/logout', 'POST');

    $log = SecurityLogger::logEvent($eventType, $user, $details, $request);

    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe($eventType)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->details)->toHaveKey('token_id')
        ->and($log->details['token_id'])->toBe('abc123');
});

test('captura información del request en detalles', function () {
    $user = User::factory()->create();
    $request = Request::create('/users/123', 'PUT', ['name' => 'New Name']);

    $log = SecurityLogger::logPermissionDenied($user, 'users.update', 'users', $request);

    expect($log->details)->toHaveKey('url')
        ->and($log->details)->toHaveKey('method')
        ->and($log->details['method'])->toBe('PUT');
});

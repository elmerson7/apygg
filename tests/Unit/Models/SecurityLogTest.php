<?php

use App\Models\Logs\SecurityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

test('puede filtrar por trace_id', function () {
    $traceId = (string) Str::uuid();

    SecurityLog::factory()->withTraceId($traceId)->count(3)->create();
    SecurityLog::factory()->count(2)->create();

    $logs = SecurityLog::byTraceId($traceId)->get();

    expect($logs)->toHaveCount(3)
        ->and($logs->every(fn ($log) => $log->trace_id === $traceId))->toBeTrue();
});

test('puede filtrar por usuario', function () {
    $user = User::factory()->create();

    SecurityLog::factory()->forUser($user->id)->count(3)->create();
    SecurityLog::factory()->count(2)->create();

    $logs = SecurityLog::byUserId($user->id)->get();

    expect($logs)->toHaveCount(3)
        ->and($logs->every(fn ($log) => $log->user_id === $user->id))->toBeTrue();
});

test('puede filtrar por tipo de evento', function () {
    SecurityLog::factory()->loginSuccess()->count(3)->create();
    SecurityLog::factory()->loginFailure()->count(2)->create();
    SecurityLog::factory()->permissionDenied()->count(1)->create();

    $loginSuccessLogs = SecurityLog::byEventType(SecurityLog::EVENT_LOGIN_SUCCESS)->get();
    $loginFailureLogs = SecurityLog::byEventType(SecurityLog::EVENT_LOGIN_FAILURE)->get();

    expect($loginSuccessLogs)->toHaveCount(3)
        ->and($loginFailureLogs)->toHaveCount(2);
});

test('puede filtrar intentos de login fallidos', function () {
    SecurityLog::factory()->loginFailure()->count(5)->create();
    SecurityLog::factory()->loginSuccess()->count(3)->create();

    $failureLogs = SecurityLog::loginFailures()->get();

    expect($failureLogs)->toHaveCount(5)
        ->and($failureLogs->every(fn ($log) => $log->event_type === SecurityLog::EVENT_LOGIN_FAILURE))->toBeTrue();
});

test('puede filtrar actividades sospechosas', function () {
    SecurityLog::factory()->suspiciousActivity()->count(4)->create();
    SecurityLog::factory()->loginSuccess()->count(2)->create();

    $suspiciousLogs = SecurityLog::suspiciousActivity()->get();

    expect($suspiciousLogs)->toHaveCount(4)
        ->and($suspiciousLogs->every(fn ($log) => $log->event_type === SecurityLog::EVENT_SUSPICIOUS_ACTIVITY))->toBeTrue();
});

test('puede filtrar por IP address', function () {
    $ipAddress = '192.168.1.1';

    SecurityLog::factory()->create(['ip_address' => $ipAddress]);
    SecurityLog::factory()->create(['ip_address' => $ipAddress]);
    SecurityLog::factory()->create(['ip_address' => '10.0.0.1']);

    $logs = SecurityLog::byIpAddress($ipAddress)->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->every(fn ($log) => $log->ip_address === $ipAddress))->toBeTrue();
});

test('puede filtrar por rango de fechas', function () {
    $startDate = now()->subDays(5);
    $endDate = now()->subDays(2);

    SecurityLog::factory()->create(['created_at' => now()->subDays(6)]);
    SecurityLog::factory()->create(['created_at' => now()->subDays(4)]);
    SecurityLog::factory()->create(['created_at' => now()->subDays(3)]);
    SecurityLog::factory()->create(['created_at' => now()->subDay()]);

    $logs = SecurityLog::dateRange($startDate->toDateString(), $endDate->toDateString())->get();

    expect($logs)->toHaveCount(2);
});

test('puede ordenar por más recientes', function () {
    SecurityLog::factory()->create(['created_at' => now()->subDays(3)]);
    SecurityLog::factory()->create(['created_at' => now()->subDays(1)]);
    SecurityLog::factory()->create(['created_at' => now()->subDays(2)]);

    $logs = SecurityLog::recent()->get();

    expect($logs->first()->created_at->gt($logs->last()->created_at))->toBeTrue();
});

test('puede verificar si evento es crítico', function () {
    $criticalLogs = [
        SecurityLog::factory()->loginFailure()->create(),
        SecurityLog::factory()->suspiciousActivity()->create(),
        SecurityLog::factory()->accountLocked()->create(),
    ];

    $nonCriticalLogs = [
        SecurityLog::factory()->loginSuccess()->create(),
        SecurityLog::factory()->permissionDenied()->create(),
    ];

    foreach ($criticalLogs as $log) {
        expect($log->isCritical())->toBeTrue();
    }

    foreach ($nonCriticalLogs as $log) {
        expect($log->isCritical())->toBeFalse();
    }
});

test('tiene relación con usuario', function () {
    $user = User::factory()->create();
    $log = SecurityLog::factory()->forUser($user->id)->create();

    expect($log->user)->not->toBeNull()
        ->and($log->user->id)->toBe($user->id);
});

test('cast correctamente details como array', function () {
    $log = SecurityLog::factory()->create([
        'details' => ['key1' => 'value1', 'key2' => 123],
    ]);

    expect($log->details)->toBeArray()
        ->and($log->details)->toHaveKey('key1')
        ->and($log->details)->toHaveKey('key2');
});

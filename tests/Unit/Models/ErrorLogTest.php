<?php

use App\Models\Logs\ErrorLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

test('puede filtrar por trace_id', function () {
    $traceId = (string) Str::uuid();

    ErrorLog::factory()->withTraceId($traceId)->count(3)->create();
    ErrorLog::factory()->count(2)->create();

    $logs = ErrorLog::byTraceId($traceId)->get();

    expect($logs)->toHaveCount(3)
        ->and($logs->every(fn ($log) => $log->trace_id === $traceId))->toBeTrue();
});

test('puede filtrar por usuario', function () {
    $user = User::factory()->create();

    ErrorLog::factory()->forUser($user->id)->count(3)->create();
    ErrorLog::factory()->count(2)->create();

    $logs = ErrorLog::byUserId($user->id)->get();

    expect($logs)->toHaveCount(3)
        ->and($logs->every(fn ($log) => $log->user_id === $user->id))->toBeTrue();
});

test('puede filtrar por severidad', function () {
    ErrorLog::factory()->low()->count(2)->create();
    ErrorLog::factory()->medium()->count(3)->create();
    ErrorLog::factory()->high()->count(1)->create();
    ErrorLog::factory()->critical()->count(2)->create();

    expect(ErrorLog::bySeverity(ErrorLog::SEVERITY_LOW)->count())->toBe(2)
        ->and(ErrorLog::bySeverity(ErrorLog::SEVERITY_MEDIUM)->count())->toBe(3)
        ->and(ErrorLog::bySeverity(ErrorLog::SEVERITY_HIGH)->count())->toBe(1)
        ->and(ErrorLog::bySeverity(ErrorLog::SEVERITY_CRITICAL)->count())->toBe(2);
});

test('puede filtrar errores no resueltos', function () {
    ErrorLog::factory()->unresolved()->count(5)->create();
    ErrorLog::factory()->resolved()->count(3)->create();

    $unresolvedLogs = ErrorLog::unresolved()->get();

    expect($unresolvedLogs)->toHaveCount(5)
        ->and($unresolvedLogs->every(fn ($log) => $log->resolved_at === null))->toBeTrue();
});

test('puede filtrar errores resueltos', function () {
    ErrorLog::factory()->unresolved()->count(5)->create();
    ErrorLog::factory()->resolved()->count(3)->create();

    $resolvedLogs = ErrorLog::resolved()->get();

    expect($resolvedLogs)->toHaveCount(3)
        ->and($resolvedLogs->every(fn ($log) => $log->resolved_at !== null))->toBeTrue();
});

test('puede filtrar errores críticos', function () {
    ErrorLog::factory()->critical()->count(3)->create();
    ErrorLog::factory()->high()->count(2)->create();

    $criticalLogs = ErrorLog::critical()->get();

    expect($criticalLogs)->toHaveCount(3)
        ->and($criticalLogs->every(fn ($log) => $log->severity === ErrorLog::SEVERITY_CRITICAL))->toBeTrue();
});

test('puede filtrar por rango de fechas', function () {
    $startDate = now()->subDays(5);
    $endDate = now()->subDays(2);

    ErrorLog::factory()->create(['created_at' => now()->subDays(6)]);
    ErrorLog::factory()->create(['created_at' => now()->subDays(4)]);
    ErrorLog::factory()->create(['created_at' => now()->subDays(3)]);
    ErrorLog::factory()->create(['created_at' => now()->subDay()]);

    $logs = ErrorLog::dateRange($startDate->toDateString(), $endDate->toDateString())->get();

    expect($logs)->toHaveCount(2);
});

test('puede ordenar por más recientes', function () {
    ErrorLog::factory()->create(['created_at' => now()->subDays(3)]);
    ErrorLog::factory()->create(['created_at' => now()->subDays(1)]);
    ErrorLog::factory()->create(['created_at' => now()->subDays(2)]);

    $logs = ErrorLog::recent()->get();

    expect($logs->first()->created_at->gt($logs->last()->created_at))->toBeTrue();
});

test('puede marcar error como resuelto', function () {
    $log = ErrorLog::factory()->unresolved()->create();

    expect($log->isResolved())->toBeFalse();

    $result = $log->markAsResolved();

    expect($result)->toBeTrue()
        ->and($log->fresh()->isResolved())->toBeTrue()
        ->and($log->fresh()->resolved_at)->not->toBeNull();
});

test('puede verificar si error está resuelto', function () {
    $unresolvedLog = ErrorLog::factory()->unresolved()->create();
    $resolvedLog = ErrorLog::factory()->resolved()->create();

    expect($unresolvedLog->isResolved())->toBeFalse()
        ->and($resolvedLog->isResolved())->toBeTrue();
});

test('tiene relación con usuario', function () {
    $user = User::factory()->create();
    $log = ErrorLog::factory()->forUser($user->id)->create();

    expect($log->user)->not->toBeNull()
        ->and($log->user->id)->toBe($user->id);
});

test('cast correctamente context como array', function () {
    $log = ErrorLog::factory()->create([
        'context' => ['key1' => 'value1', 'key2' => 123],
    ]);

    expect($log->context)->toBeArray()
        ->and($log->context)->toHaveKey('key1')
        ->and($log->context)->toHaveKey('key2');
});

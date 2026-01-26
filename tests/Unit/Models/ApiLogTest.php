<?php

use App\Models\Logs\ApiLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('puede filtrar por trace_id', function () {
    $traceId = (string) Str::uuid();

    ApiLog::factory()->withTraceId($traceId)->count(3)->create();
    ApiLog::factory()->count(2)->create();

    $logs = ApiLog::byTraceId($traceId)->get();

    expect($logs)->toHaveCount(3)
        ->and($logs->every(fn ($log) => $log->trace_id === $traceId))->toBeTrue();
});

test('puede filtrar por usuario', function () {
    $user = User::factory()->create();

    ApiLog::factory()->forUser($user->id)->count(3)->create();
    ApiLog::factory()->count(2)->create();

    $logs = ApiLog::byUserId($user->id)->get();

    expect($logs)->toHaveCount(3)
        ->and($logs->every(fn ($log) => $log->user_id === $user->id))->toBeTrue();
});

test('puede filtrar por método HTTP', function () {
    ApiLog::factory()->forEndpoint('GET', '/api/users')->count(3)->create();
    ApiLog::factory()->forEndpoint('POST', '/api/users')->count(2)->create();

    $getLogs = ApiLog::byMethod('GET')->get();
    $postLogs = ApiLog::byMethod('POST')->get();

    expect($getLogs)->toHaveCount(3)
        ->and($postLogs)->toHaveCount(2);
});

test('puede filtrar por código de estado', function () {
    ApiLog::factory()->create(['response_status' => 200]);
    ApiLog::factory()->create(['response_status' => 201]);
    ApiLog::factory()->create(['response_status' => 400]);
    ApiLog::factory()->create(['response_status' => 404]);

    $successLogs = ApiLog::byStatus(200)->get();
    $errorLogs = ApiLog::byStatus(400)->get();

    expect($successLogs->count())->toBe(1)
        ->and($errorLogs->count())->toBe(1);
});

test('puede filtrar requests lentos', function () {
    ApiLog::factory()->slow(1000)->count(3)->create();
    ApiLog::factory()->create(['response_time_ms' => 500]);

    $slowLogs = ApiLog::slowRequests(1000)->get();

    expect($slowLogs)->toHaveCount(3)
        ->and($slowLogs->every(fn ($log) => $log->response_time_ms > 1000))->toBeTrue();
});

test('puede filtrar por rango de fechas', function () {
    $startDate = now()->subDays(5);
    $endDate = now()->subDays(2);

    ApiLog::factory()->create(['created_at' => now()->subDays(6)]);
    ApiLog::factory()->create(['created_at' => now()->subDays(4)]);
    ApiLog::factory()->create(['created_at' => now()->subDays(3)]);
    ApiLog::factory()->create(['created_at' => now()->subDay()]);

    $logs = ApiLog::dateRange($startDate->toDateString(), $endDate->toDateString())->get();

    expect($logs)->toHaveCount(2);
});

test('puede ordenar por más recientes', function () {
    ApiLog::factory()->create(['created_at' => now()->subDays(3)]);
    ApiLog::factory()->create(['created_at' => now()->subDays(1)]);
    ApiLog::factory()->create(['created_at' => now()->subDays(2)]);

    $logs = ApiLog::recent()->get();

    expect($logs->first()->created_at->gt($logs->last()->created_at))->toBeTrue();
});

test('tiene relación con usuario', function () {
    $user = User::factory()->create();
    $log = ApiLog::factory()->forUser($user->id)->create();

    expect($log->user)->not->toBeNull()
        ->and($log->user->id)->toBe($user->id);
});

test('cast correctamente arrays en campos JSON', function () {
    $log = ApiLog::factory()->create([
        'request_query' => ['page' => 1],
        'request_body' => ['name' => 'Test'],
        'request_headers' => ['content-type' => 'application/json'],
        'response_body' => ['success' => true],
    ]);

    expect($log->request_query)->toBeArray()
        ->and($log->request_body)->toBeArray()
        ->and($log->request_headers)->toBeArray()
        ->and($log->response_body)->toBeArray();
});

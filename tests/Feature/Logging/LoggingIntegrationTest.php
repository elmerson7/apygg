<?php

use App\Models\Logs\ApiLog;
use App\Models\Logs\ErrorLog;
use App\Models\Logs\SecurityLog;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Logging\ApiLogger;
use App\Services\Logging\AuthLogger;
use App\Services\Logging\SecurityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->traceId = (string) Str::uuid();
    $this->ipAddress = '192.168.1.100';
    $this->userAgent = 'Mozilla/5.0 Test Agent';
});

test('ActivityLogger guarda contexto completo (user_id, IP)', function () {
    Auth::login($this->user);

    $user = User::factory()->create();
    $log = ActivityLogger::logCreated($user);

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->ip_address)->not->toBeNull()
        ->and($log->model_type)->toBe(User::class)
        ->and($log->model_id)->toBe($user->id);

    // Verificar en base de datos
    $this->assertDatabaseHas('logs_activity', [
        'id' => $log->id,
        'user_id' => $this->user->id,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);
});

test('ApiLogger guarda contexto completo (trace_id, user_id, IP, user_agent)', function () {
    \Illuminate\Support\Facades\Auth::login($this->user);

    $request = Request::create('/api/users', 'GET');
    $request->server->set('REMOTE_ADDR', $this->ipAddress);
    $request->headers->set('User-Agent', $this->userAgent);
    $request->headers->set('X-Trace-ID', $this->traceId);
    $response = new Response(['success' => true], 200);

    $log = ApiLogger::logRequest($request, $response, 150.5, $this->traceId);

    expect($log)->not->toBeNull()
        ->and($log->trace_id)->toBe($this->traceId)
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->ip_address)->toBe($this->ipAddress)
        ->and($log->user_agent)->toBe($this->userAgent)
        ->and($log->request_method)->toBe('GET')
        ->and($log->request_path)->toBe('api/users') // Request::create() remueve el slash inicial
        ->and($log->response_status)->toBe(200);

    // Verificar en base de datos
    $this->assertDatabaseHas('logs_api', [
        'id' => $log->id,
        'trace_id' => $this->traceId,
        'user_id' => $this->user->id,
        'ip_address' => $this->ipAddress,
        'user_agent' => $this->userAgent,
    ]);
});

test('AuthLogger guarda contexto completo (user_id, IP, user_agent)', function () {
    $log = AuthLogger::logLoginSuccess($this->user, $this->ipAddress, $this->userAgent);

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->ip_address)->toBe($this->ipAddress)
        ->and($log->user_agent)->toBe($this->userAgent)
        ->and($log->event_type)->toBe(SecurityLog::EVENT_LOGIN_SUCCESS)
        ->and($log->details)->toHaveKey('login_at');

    // Verificar en base de datos
    $this->assertDatabaseHas('logs_security', [
        'id' => $log->id,
        'user_id' => $this->user->id,
        'event_type' => SecurityLog::EVENT_LOGIN_SUCCESS,
        'ip_address' => $this->ipAddress,
        'user_agent' => $this->userAgent,
    ]);
});

test('SecurityLogger guarda contexto completo (user_id, IP, user_agent, trace_id)', function () {
    $request = Request::create('/api/users/123', 'DELETE');
    $request->server->set('REMOTE_ADDR', $this->ipAddress);
    $request->headers->set('User-Agent', $this->userAgent);
    $request->headers->set('X-Trace-ID', $this->traceId);

    $log = SecurityLogger::logPermissionDenied($this->user, 'users.delete', 'users', $request);

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->ip_address)->toBe($this->ipAddress)
        ->and($log->user_agent)->toBe($this->userAgent)
        ->and($log->event_type)->toBe(SecurityLog::EVENT_PERMISSION_DENIED)
        ->and($log->details)->toHaveKey('permission')
        ->and($log->details)->toHaveKey('method');

    // Verificar en base de datos
    $this->assertDatabaseHas('logs_security', [
        'id' => $log->id,
        'user_id' => $this->user->id,
        'event_type' => SecurityLog::EVENT_PERMISSION_DENIED,
        'ip_address' => $this->ipAddress,
        'user_agent' => $this->userAgent,
    ]);
});

test('ErrorLog puede relacionarse con ApiLog mediante trace_id', function () {
    // Crear ApiLog con trace_id
    $apiLog = ApiLog::factory()->withTraceId($this->traceId)->forUser($this->user->id)->create();

    // Crear ErrorLog con el mismo trace_id
    $errorLog = ErrorLog::factory()
        ->withTraceId($this->traceId)
        ->forUser($this->user->id)
        ->critical()
        ->create();

    // Verificar relación
    expect($errorLog->apiLog)->not->toBeNull()
        ->and($errorLog->apiLog->id)->toBe($apiLog->id)
        ->and($errorLog->trace_id)->toBe($this->traceId)
        ->and($apiLog->trace_id)->toBe($this->traceId);
});

test('SecurityLog puede relacionarse con ApiLog mediante trace_id', function () {
    // Crear ApiLog con trace_id
    $apiLog = ApiLog::factory()->withTraceId($this->traceId)->forUser($this->user->id)->create();

    // Crear SecurityLog con el mismo trace_id
    $securityLog = SecurityLog::factory()
        ->withTraceId($this->traceId)
        ->forUser($this->user->id)
        ->permissionDenied()
        ->create();

    // Verificar relación
    expect($securityLog->apiLog)->not->toBeNull()
        ->and($securityLog->apiLog->id)->toBe($apiLog->id)
        ->and($securityLog->trace_id)->toBe($this->traceId)
        ->and($apiLog->trace_id)->toBe($this->traceId);
});

test('todos los logs pueden tener user_id null para acciones no autenticadas', function () {
    // ApiLog sin usuario
    $apiLog = ApiLog::factory()->create(['user_id' => null]);
    expect($apiLog->user_id)->toBeNull();

    // SecurityLog sin usuario (login failure)
    $securityLog = SecurityLog::factory()->loginFailure()->create();
    expect($securityLog->user_id)->toBeNull();

    // ErrorLog sin usuario
    $errorLog = ErrorLog::factory()->create(['user_id' => null]);
    expect($errorLog->user_id)->toBeNull();
});

test('los logs preservan información de contexto en detalles JSON', function () {
    $request = Request::create('/api/users', 'POST', ['name' => 'Test']);
    $request->setUserResolver(fn () => $this->user);
    $request->headers->set('X-Trace-ID', $this->traceId);

    $securityLog = SecurityLogger::logSuspiciousActivity(
        'Multiple failed attempts',
        $this->user,
        ['attempts' => 5, 'ip' => $this->ipAddress],
        $request
    );

    expect($securityLog->details)->toBeArray()
        ->and($securityLog->details)->toHaveKey('description')
        ->and($securityLog->details)->toHaveKey('attempts')
        ->and($securityLog->details)->toHaveKey('ip')
        ->and($securityLog->details['attempts'])->toBe(5);
});

test('los logs se pueden consultar por trace_id para rastrear request completo', function () {
    // Crear logs relacionados con el mismo trace_id
    $apiLog = ApiLog::factory()->withTraceId($this->traceId)->forUser($this->user->id)->create();
    $errorLog = ErrorLog::factory()->withTraceId($this->traceId)->forUser($this->user->id)->create();
    $securityLog = SecurityLog::factory()->withTraceId($this->traceId)->forUser($this->user->id)->create();

    // Consultar todos los logs por trace_id
    $apiLogs = ApiLog::byTraceId($this->traceId)->get();
    $errorLogs = ErrorLog::byTraceId($this->traceId)->get();
    $securityLogs = SecurityLog::byTraceId($this->traceId)->get();

    expect($apiLogs)->toHaveCount(1)
        ->and($errorLogs)->toHaveCount(1)
        ->and($securityLogs)->toHaveCount(1)
        ->and($apiLogs->first()->trace_id)->toBe($this->traceId)
        ->and($errorLogs->first()->trace_id)->toBe($this->traceId)
        ->and($securityLogs->first()->trace_id)->toBe($this->traceId);
});

<?php

use App\Models\User;
use App\Services\Logging\ApiLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('puede registrar request y response de API', function () {
    $request = Request::create('/users', 'GET');
    $response = new Response(['data' => []], 200);
    $traceId = (string) Str::uuid();

    $log = ApiLogger::logRequest($request, $response, 150.5, $traceId);

    expect($log)->not->toBeNull()
        ->and($log->trace_id)->toBe($traceId)
        ->and($log->request_method)->toBe('GET')
        ->and($log->request_path)->toBe('users') // Request::create() remueve el slash inicial
        ->and($log->response_status)->toBe(200)
        ->and($log->response_time_ms)->toBe(151); // redondeado
});

test('genera trace_id si no se proporciona', function () {
    $request = Request::create('/users', 'POST');
    $response = new Response(['success' => true], 201);

    $log = ApiLogger::logRequest($request, $response);

    expect($log)->not->toBeNull()
        ->and($log->trace_id)->not->toBeNull()
        ->and(Str::isUuid($log->trace_id))->toBeTrue();
});

test('usa trace_id del header X-Trace-ID si existe', function () {
    $traceId = (string) Str::uuid();
    $request = Request::create('/users', 'GET');
    $request->headers->set('X-Trace-ID', $traceId);
    $response = new Response([], 200);

    $log = ApiLogger::logRequest($request, $response);

    expect($log->trace_id)->toBe($traceId);
});

test('excluye rutas configuradas del logging', function () {
    $request = Request::create('/health', 'GET');
    $response = new Response([], 200);

    $log = ApiLogger::logRequest($request, $response);

    expect($log)->toBeNull();
});

test('excluye ruta raíz del logging', function () {
    $request = Request::create('/', 'GET');
    $response = new Response([], 200);

    $log = ApiLogger::logRequest($request, $response);

    expect($log)->toBeNull();
});

test('excluye rutas que contienen paths excluidos', function () {
    $paths = ['/telescope/requests', '/horizon/dashboard', '/health/check'];

    foreach ($paths as $path) {
        $request = Request::create($path, 'GET');
        $response = new Response([], 200);

        $log = ApiLogger::logRequest($request, $response);

        expect($log)->toBeNull("Path {$path} should be excluded");
    }
});

test('sanitiza campos sensibles en query parameters', function () {
    $request = Request::create('/users?password=secret&token=abc123&name=test', 'GET');
    $response = new Response([], 200);

    $log = ApiLogger::logRequest($request, $response);

    expect($log->request_query)->toHaveKey('password')
        ->and($log->request_query)->toHaveKey('token')
        ->and($log->request_query['password'])->toBe('[REDACTED]')
        ->and($log->request_query['token'])->toBe('[REDACTED]')
        ->and($log->request_query['name'])->toBe('test');
});

test('sanitiza campos sensibles en request body', function () {
    $request = Request::create('/users', 'POST', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'api_token' => 'token123',
    ]);
    $response = new Response([], 201);

    $log = ApiLogger::logRequest($request, $response);

    expect($log->request_body)->toHaveKey('password')
        ->and($log->request_body)->toHaveKey('password_confirmation')
        ->and($log->request_body)->toHaveKey('api_token')
        ->and($log->request_body['password'])->toBe('[REDACTED]')
        ->and($log->request_body['password_confirmation'])->toBe('[REDACTED]')
        ->and($log->request_body['api_token'])->toBe('[REDACTED]')
        ->and($log->request_body['name'])->toBe('Test User')
        ->and($log->request_body['email'])->toBe('test@example.com');
});

test('sanitiza headers sensibles', function () {
    $request = Request::create('/users', 'GET');
    $request->headers->set('Authorization', 'Bearer token123');
    $request->headers->set('X-Api-Key', 'key123');
    $request->headers->set('Content-Type', 'application/json');
    $response = new Response([], 200);

    $log = ApiLogger::logRequest($request, $response);

    expect($log->request_headers)->toHaveKey('authorization')
        ->and($log->request_headers)->toHaveKey('x-api-key')
        ->and($log->request_headers['authorization'])->toBe('[REDACTED]')
        ->and($log->request_headers['x-api-key'])->toBe('[REDACTED]')
        ->and($log->request_headers['content-type'])->toBe('application/json');
});

test('calcula tiempo de respuesta si no se proporciona', function () {
    $request = Request::create('/users', 'GET');
    $request->server->set('REQUEST_TIME_FLOAT', microtime(true) - 0.1);
    $response = new Response([], 200);

    $log = ApiLogger::logRequest($request, $response);

    expect($log->response_time_ms)->toBeGreaterThan(0)
        ->and($log->response_time_ms)->toBeLessThan(10000);
});

test('captura usuario autenticado si existe', function () {
    \Illuminate\Support\Facades\Auth::login($this->user);

    $request = Request::create('/users', 'GET');
    $response = new Response([], 200);

    $log = ApiLogger::logRequest($request, $response);

    expect($log->user_id)->toBe($this->user->id);
});

test('captura IP address y user agent', function () {
    $request = Request::create('/users', 'GET');
    $request->server->set('REMOTE_ADDR', '192.168.1.1');
    $request->headers->set('User-Agent', 'Test Agent');
    $response = new Response([], 200);

    $log = ApiLogger::logRequest($request, $response);

    expect($log->ip_address)->toBe('192.168.1.1')
        ->and($log->user_agent)->toBe('Test Agent');
});

test('puede agregar rutas a la lista de excluidas', function () {
    ApiLogger::excludePaths(['custom-path']);

    $request = Request::create('/custom-path', 'GET');
    $response = new Response([], 200);

    $log = ApiLogger::logRequest($request, $response);

    expect($log)->toBeNull();
});

test('puede agregar headers a la lista de excluidos', function () {
    ApiLogger::excludeHeaders(['custom-header']);

    $request = Request::create('/users', 'GET');
    $request->headers->set('Custom-Header', 'secret-value');
    $response = new Response([], 200);

    $log = ApiLogger::logRequest($request, $response);

    expect($log->request_headers)->toHaveKey('custom-header')
        ->and($log->request_headers['custom-header'])->toBe('[REDACTED]');
});

test('captura response body si es JSON', function () {
    $request = Request::create('/users', 'GET');
    $responseData = ['success' => true, 'data' => []];
    $response = new Response(json_encode($responseData), 200);
    $response->headers->set('Content-Type', 'application/json');

    $log = ApiLogger::logRequest($request, $response);

    expect($log)->not->toBeNull()
        ->and($log->response_body)->toBeArray()
        ->and($log->response_body)->toHaveKey('success')
        ->and($log->response_body)->toHaveKey('data');
});

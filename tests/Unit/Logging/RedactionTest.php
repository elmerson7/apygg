<?php

use App\Helpers\Redactor;
use App\Models\User;
use App\Services\Logging\ApiLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

uses(RefreshDatabase::class);

test('oculta contraseñas y tokens en cuerpos anidados', function () {
    $data = [
        'nombre' => 'x',
        'current_password' => 'secreto-actual',
        'data' => [
            'access_token' => 'AAA.BBB.CCC',
            'refresh_token' => 'refresh-xyz',
            'nested' => ['Authorization' => 'Bearer zzz', 'api_key' => 'k-123'],
        ],
    ];

    $out = Redactor::redact($data);

    expect($out['nombre'])->toBe('x')
        ->and($out['current_password'])->toBe('[REDACTED]')
        ->and($out['data']['access_token'])->toBe('[REDACTED]')
        ->and($out['data']['refresh_token'])->toBe('[REDACTED]')
        ->and($out['data']['nested']['Authorization'])->toBe('[REDACTED]')
        ->and($out['data']['nested']['api_key'])->toBe('[REDACTED]');
});

test('la lista de claves sensibles es configurable', function () {
    config(['logging.redact.keys' => ['mi_campo']]);

    expect(Redactor::redact(['mi_campo' => 'x', 'otro' => 'y']))->toBe(['mi_campo' => '[REDACTED]', 'otro' => 'y']);
});

test('ApiLogger no guarda tokens ni contraseñas del request ni de la response', function () {
    $user = User::factory()->create();
    $request = Request::create('/auth/login', 'POST', [
        'email' => 'a@b.com',
        'password' => 'PlainPass123!',
        'current_password' => 'OldPass123!',
    ]);
    $response = new Response(json_encode([
        'success' => true,
        'data' => ['access_token' => 'ACCESS-TOKEN-VALUE', 'refresh_token' => 'REFRESH-TOKEN-VALUE'],
    ]), 200, ['Content-Type' => 'application/json']);

    $log = ApiLogger::logRequest($request, $response);
    $stored = json_encode([$log->request_body, $log->response_body]);

    expect($stored)->not->toContain('PlainPass123!')
        ->and($stored)->not->toContain('OldPass123!')
        ->and($stored)->not->toContain('ACCESS-TOKEN-VALUE')
        ->and($stored)->not->toContain('REFRESH-TOKEN-VALUE')
        ->and($log->request_body['email'])->toBe('a***@b***.com');
});

test('oculta el code de un OTP, un TOTP o un código de recuperación y conserva los códigos de error', function () {
    $out = Redactor::redact([
        'login' => ['email' => 'x', 'code' => '48213907'],
        'totp' => ['code' => 123456],
        'recovery' => ['code' => 'AbCdEfGhIj-KlMnOpQrSt'],
        'error' => ['code' => 'validation_error', 'status' => 422],
        'sql' => ['code' => '23505'],
    ]);

    expect($out['login']['code'])->toBe(Redactor::MASK)
        ->and($out['totp']['code'])->toBe(Redactor::MASK)
        ->and($out['recovery']['code'])->toBe(Redactor::MASK)
        ->and($out['error']['code'])->toBe('validation_error')
        ->and($out['sql']['code'])->toBe('23505');
});

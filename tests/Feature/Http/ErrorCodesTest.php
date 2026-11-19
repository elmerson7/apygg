<?php

use App\Enums\ErrorCode;
use App\Exceptions\AccountBlockedException;
use App\Exceptions\ApiException;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\LoginLockedException;
use App\Http\Controllers\Concerns\RespondsWithApi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

// Contrato: TODA respuesta de error lleva `code` (estable, legible por máquina) y
// `request_id` (el mismo que la cabecera X-Request-ID). Ver docs/api-response-structure.md.

beforeEach(function () {
    Route::middleware('api')->group(function () {
        Route::get('/_err/jwt-invalido', fn () => throw new TokenInvalidException);
        Route::get('/_err/jwt-expirado', fn () => throw new TokenExpiredException);
        Route::get('/_err/nf', fn () => abort(404));
        Route::get('/_err/conflict', fn () => throw ApiException::conflict('Choca'));
        Route::get('/_err/propio', fn () => throw new class('Factura pagada', 409) extends ApiException
        {
            public function errorCode(): ErrorCode|string
            {
                return 'invoice_already_paid';
            }
        });
        Route::get('/_err/negocio', fn () => throw BusinessLogicException::ruleViolation('No', 'X'));
        Route::get('/_err/bloqueada', fn () => throw new AccountBlockedException);
        Route::get('/_err/login-locked', fn () => throw new LoginLockedException(5));
        Route::get('/_err/boom', fn () => throw new RuntimeException('secreto interno'));
        Route::get('/_err/send-error', fn () => (new class
        {
            use RespondsWithApi;

            public function go()
            {
                return $this->sendError('Mal', 409);
            }
        })->go());
        Route::get('/_err/limitado', fn () => 'ok')->middleware('throttle:1,1');
    });
});

function verificarError($response, int $status, string $code): array
{
    $response->assertStatus($status);
    $body = $response->json();

    expect($body['code'])->toBe($code)
        ->and($body['request_id'])->toBeString()->not->toBeEmpty()
        ->and($response->headers->get('X-Request-ID'))->toBe($body['request_id']);

    return $body;
}

test('responde 401 sin token con code unauthenticated y request_id', function () {
    $body = verificarError($this->getJson('/users'), 401, 'unauthenticated');
    expect($body['type'])->toBe('unauthorized');
});

test('responde 401 con code token_invalid o token_expired según la excepción JWT', function () {
    verificarError($this->getJson('/_err/jwt-invalido'), 401, 'token_invalid');
    verificarError($this->getJson('/_err/jwt-expirado'), 401, 'token_expired');
});

test('responde 401 unauthenticated con un Bearer que no es un JWT', function () {
    verificarError($this->getJson('/users', ['Authorization' => 'Bearer no-es-un-jwt']), 401, 'unauthenticated');
});

test('responde 403 con code forbidden cuando falta el permiso', function () {
    $user = User::factory()->create();
    $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];

    $body = verificarError($this->getJson('/admin/logs/api', $headers), 403, 'forbidden');
    expect($body['type'])->toBe('forbidden')->and($body['status'])->toBe(403);
});

test('responde 404 con code not_found', function () {
    $body = verificarError($this->getJson('/_err/nf'), 404, 'not_found');
    expect($body['type'])->toBe('not_found')->and($body['status'])->toBe(404);
});

test('responde 405 con code method_not_allowed en una ruta que existe con otro método', function () {
    verificarError($this->deleteJson('/health'), 405, 'method_not_allowed');
});

test('una ruta que no existe responde 404 con code not_found', function () {
    verificarError($this->getJson('/ruta-que-no-existe'), 404, 'not_found');
});

test('responde 409 con code conflict', function () {
    verificarError($this->getJson('/_err/conflict'), 409, 'conflict');
});

test('responde 409 con el code propio que declara una excepción de proyecto', function () {
    verificarError($this->getJson('/_err/propio'), 409, 'invoice_already_paid');
});

test('responde 422 de validación con code validation_failed y conserva errors y success', function () {
    $body = verificarError($this->postJson('/auth/login', []), 422, 'validation_failed');
    expect($body['success'])->toBeFalse()
        ->and($body['errors'])->toBeArray()->not->toBeEmpty()
        ->and($body['type'])->toBe('validation_error');
});

test('responde 422 con code business_rule en una BusinessLogicException y conserva business_code', function () {
    $body = verificarError($this->getJson('/_err/negocio'), 422, 'business_rule');
    expect($body['business_code'])->toBe('X');
});

test('responde 429 con code rate_limited y request_id', function () {
    $this->getJson('/_err/limitado')->assertOk();

    verificarError($this->getJson('/_err/limitado'), 429, 'rate_limited');
});

test('responde 500 simulado con code server_error sin filtrar el mensaje', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/_err/boom');
    $body = verificarError($response, 500, 'server_error');
    expect($response->getContent())->not->toContain('secreto interno')
        ->and($body['type'])->toBe('internal_server_error');
});

test('las excepciones de cuenta llevan sus codes account_blocked y account_locked', function () {
    verificarError($this->getJson('/_err/bloqueada'), 403, 'account_blocked');
    $body = verificarError($this->getJson('/_err/login-locked'), 401, 'account_locked');
    expect($body['lockout_minutes'])->toBe(5);
});

test('sendError añade code y request_id sin quitar success ni message', function () {
    $body = verificarError($this->getJson('/_err/send-error'), 409, 'conflict');
    expect($body['success'])->toBeFalse()->and($body['message'])->toBe('Mal');
});

test('respeta un X-Request-ID entrante válido en la cabecera y en el cuerpo', function () {
    $id = 'cliente-123.abc:XYZ';

    $response = $this->getJson('/_err/nf', ['X-Request-ID' => $id])->assertNotFound();

    expect($response->headers->get('X-Request-ID'))->toBe($id)
        ->and($response->json('request_id'))->toBe($id);
});

test('respeta un UUID entrante', function () {
    $id = '3f2b8c1e-6a4d-4e0b-9d57-1c2a7b9e5f10';

    $this->getJson('/health', ['X-Request-ID' => $id])->assertHeader('X-Request-ID', $id);
});

test('reemplaza un X-Request-ID inválido por un UUID generado', function () {
    foreach (['con espacios y <script>', str_repeat('a', 65), 'a/b'] as $malo) {
        $response = $this->getJson('/_err/nf', ['X-Request-ID' => $malo])->assertNotFound();
        $id = $response->headers->get('X-Request-ID');

        expect($id)->not->toBe($malo)
            ->and($id)->toMatch('/^[0-9a-f-]{36}$/')
            ->and($response->json('request_id'))->toBe($id);
    }
});

test('devuelve X-Request-ID también en respuestas correctas', function () {
    expect($this->getJson('/health')->headers->get('X-Request-ID'))->toBeString()->not->toBeEmpty();
});

test('cada case de ErrorCode tiene valor snake_case único', function () {
    $values = array_map(fn (ErrorCode $c) => $c->value, ErrorCode::cases());

    expect($values)->each->toMatch('/^[a-z]+(_[a-z]+)*$/')
        ->and(array_unique($values))->toHaveCount(count($values));
});

test('resolve acepta un case, un string propio o deduce por type y status', function () {
    expect(ErrorCode::resolve(ErrorCode::Duplicate, 422))->toBe('duplicate')
        ->and(ErrorCode::resolve('mi_codigo', 400))->toBe('mi_codigo')
        ->and(ErrorCode::resolve(null, 422, 'validation_error'))->toBe('validation_failed')
        ->and(ErrorCode::resolve(null, 418))->toBe('http_error')
        ->and(ErrorCode::resolve(null, 502))->toBe('upstream_error');
});

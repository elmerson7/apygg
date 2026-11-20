<?php

use PragmaRX\Google2FA\Google2FA;

/*
| Recorrido E2E "sesiones y 2FA" por HTTP real (docs/sessions-and-2fa.md):
|   dos sesiones -> revocar una desde la otra (su access deja de valer al instante) -> activar 2FA con un TOTP real
|   -> el login pide el desafío -> código malo, TOTP bueno, código de recuperación de un solo uso -> desactivar 2FA
|   -> cambiar la contraseña invalida todo.
| Los TOTP se calculan en el test con el secreto que entrega la API (pragmarx/google2fa, como el autenticador).
*/

const E2E_SE = 'sesiones';

test('cada login abre una sesion y la actual se marca como tal', function () {
    $u = e2eUsuario(E2E_SE, 'persona');
    e2e(E2E_SE)['u'] = $u;

    // Segundo dispositivo: otro login con otro User-Agent.
    $login = e2eApi()->withUserAgent('E2E-Movil/1.0')->post('/auth/login', ['login' => $u['email'], 'password' => $u['password']]);
    expect($login->status())->toBe(200);
    e2e(E2E_SE)['movil'] = (string) $login->json('data.access_token');

    $sesiones = e2eItems(e2eApi($u['access'])->get('/user/sessions'));
    expect($sesiones)->toHaveCount(2)
        ->and(collect($sesiones)->where('current', true))->toHaveCount(1);

    $otra = collect($sesiones)->firstWhere('current', false);
    e2e(E2E_SE)['sesion_movil'] = $otra['id'];
});

test('revocar la otra sesion invalida su access de inmediato y no toca la actual', function () {
    $s = e2e(E2E_SE);

    expect(e2eApi($s['movil'])->get('/auth/me')->status())->toBe(200);
    expect(e2eApi($s['u']['access'])->delete('/user/sessions/'.$s['sesion_movil'])->status())->toBe(200);

    expect(e2eApi($s['movil'])->get('/auth/me')->status())->toBe(401);
    expect(e2eApi($s['u']['access'])->get('/auth/me')->status())->toBe(200);
    // Revocar dos veces: ya no existe.
    expect(e2eApi($s['u']['access'])->delete('/user/sessions/'.$s['sesion_movil'])->status())->toBe(404);
})->depends('cada login abre una sesion y la actual se marca como tal');

test('nadie revoca sesiones ajenas', function () {
    $s = e2e(E2E_SE);
    $otro = e2eUsuario(E2E_SE, 'otra-persona');
    $suya = collect(e2eItems(e2eApi($otro['access'])->get('/user/sessions')))->first();

    expect(e2eApi($s['u']['access'])->delete('/user/sessions/'.$suya['id'])->status())->toBe(404);
    expect(e2eApi($otro['access'])->get('/auth/me')->status())->toBe(200);
})->depends('revocar la otra sesion invalida su access de inmediato y no toca la actual');

test('activar el 2FA con un TOTP real devuelve ocho codigos de recuperacion', function () {
    $s = e2e(E2E_SE);
    $google = new Google2FA;

    $setup = e2eApi($s['u']['access'])->post('/user/2fa/setup');
    expect($setup->status())->toBe(200);
    $secreto = (string) $setup->json('data.secret');
    expect($secreto)->not->toBeEmpty()->and($setup->json('data.otpauth_url'))->toStartWith('otpauth://totp/');

    // Un código incorrecto no activa nada.
    expect(e2eApi($s['u']['access'])->post('/user/2fa/confirm', ['code' => '000000'])->status())->toBeIn([401, 422]);

    $confirma = e2eApi($s['u']['access'])->post('/user/2fa/confirm', ['code' => $google->getCurrentOtp($secreto)]);
    expect($confirma->status())->toBe(200, $confirma->body())
        ->and($confirma->json('data.recovery_codes'))->toHaveCount(8);

    e2e(E2E_SE)['secreto'] = $secreto;
    e2e(E2E_SE)['recuperacion'] = $confirma->json('data.recovery_codes');
    expect(e2eApi($s['u']['access'])->get('/user/2fa')->json('data.enabled'))->toBeTrue();
})->depends('nadie revoca sesiones ajenas');

test('con 2FA el login no da tokens: pide el desafio y solo un TOTP valido lo completa', function () {
    $s = e2e(E2E_SE);
    $google = new Google2FA;

    $login = e2ePostAuth('/auth/login', ['login' => $s['u']['email'], 'password' => $s['u']['password']]);
    expect($login->status())->toBe(200)
        ->and($login->json('data.two_factor_required'))->toBeTrue()
        ->and($login->json('data.access_token'))->toBeNull();
    $desafio = (string) $login->json('data.challenge_token');

    // El desafío no es un JWT: no autentica nada.
    expect(e2eApi($desafio)->get('/auth/me')->status())->toBe(401);

    // Código erróneo: 401 genérico.
    $malo = e2ePostAuth('/auth/2fa/verify', ['challenge_token' => $desafio, 'code' => '123456']);
    expect($malo->status())->toBe(401)->and($malo->json('code'))->toBe('two_factor_invalid');

    // TOTP de la ventana siguiente (el actual ya se gastó al confirmar: cada código vale una vez).
    $bueno = e2ePostAuth('/auth/2fa/verify', [
        'challenge_token' => $desafio,
        'code' => $google->oathTotp($s['secreto'], $google->getTimestamp() + 1),
    ]);
    expect($bueno->status())->toBe(200, $bueno->body())
        ->and($bueno->json('data.access_token'))->toBeString();
    e2e(E2E_SE)['access_2fa'] = (string) $bueno->json('data.access_token');
    expect(e2eApi($bueno->json('data.access_token'))->get('/auth/me')->json('data.email'))->toBe($s['u']['email']);

    // El desafío ya se consumió.
    $reuso = e2ePostAuth('/auth/2fa/verify', ['challenge_token' => $desafio, 'code' => $google->oathTotp($s['secreto'], $google->getTimestamp() - 1)]);
    expect($reuso->status())->toBe(401);
})->depends('activar el 2FA con un TOTP real devuelve ocho codigos de recuperacion');

test('un codigo de recuperacion completa el login una sola vez', function () {
    $s = e2e(E2E_SE);
    $codigo = $s['recuperacion'][0];

    $primero = e2ePostAuth('/auth/login', ['login' => $s['u']['email'], 'password' => $s['u']['password']]);
    $ok = e2ePostAuth('/auth/2fa/verify', ['challenge_token' => $primero->json('data.challenge_token'), 'code' => $codigo]);
    expect($ok->status())->toBe(200, $ok->body());

    $segundo = e2ePostAuth('/auth/login', ['login' => $s['u']['email'], 'password' => $s['u']['password']]);
    $repetido = e2ePostAuth('/auth/2fa/verify', ['challenge_token' => $segundo->json('data.challenge_token'), 'code' => $codigo]);
    expect($repetido->status())->toBe(401)->and($repetido->json('code'))->toBe('two_factor_invalid');
})->depends('con 2FA el login no da tokens: pide el desafio y solo un TOTP valido lo completa');

test('desactivar el 2FA exige contrasena y codigo y devuelve el login normal', function () {
    $s = e2e(E2E_SE);
    $google = new Google2FA;
    $token = $s['access_2fa'];

    $sinClave = e2eApi($token)->delete('/user/2fa', ['password' => 'Incorrecta-123!x', 'code' => $google->oathTotp($s['secreto'], $google->getTimestamp() - 1)]);
    expect($sinClave->status())->toBeIn([401, 403, 422]);

    $ok = e2eApi($token)->delete('/user/2fa', ['password' => $s['u']['password'], 'code' => $s['recuperacion'][1]]);
    expect($ok->status())->toBe(200, $ok->body());

    $login = e2ePostAuth('/auth/login', ['login' => $s['u']['email'], 'password' => $s['u']['password']]);
    expect($login->json('data.access_token'))->toBeString();
    e2e(E2E_SE)['access_final'] = (string) $login->json('data.access_token');
})->depends('un codigo de recuperacion completa el login una sola vez');

test('cambiar la contrasena invalida los tokens anteriores', function () {
    $s = e2e(E2E_SE);
    $nueva = 'E2e-Nueva-'.bin2hex(random_bytes(6)).'!8';

    $cambio = e2eApi($s['access_final'])->post('/auth/change-password', [
        'current_password' => $s['u']['password'],
        'password' => $nueva,
        'password_confirmation' => $nueva,
    ]);
    expect($cambio->status())->toBe(200, $cambio->body());

    // Todas las sesiones anteriores quedan revocadas.
    expect(e2eApi($s['access_final'])->get('/auth/me')->status())->toBe(401);
    expect(e2eApi($s['u']['access'])->get('/auth/me')->status())->toBe(401);

    // La clave vieja ya no entra y la nueva sí.
    expect(e2ePostAuth('/auth/login', ['login' => $s['u']['email'], 'password' => $s['u']['password']])->status())->toBe(401);
    expect(e2ePostAuth('/auth/login', ['login' => $s['u']['email'], 'password' => $nueva])->status())->toBe(200);
})->depends('desactivar el 2FA exige contrasena y codigo y devuelve el login normal');

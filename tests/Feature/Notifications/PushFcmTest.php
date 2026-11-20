<?php

use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\Exceptions\PushTokenInvalidError;
use App\Integrations\Exceptions\RateLimitedError;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\Push\FcmPushTransport;
use App\Integrations\Push\PushMessage;
use Illuminate\Support\Facades\Http;

function fcm(array $over = []): FcmPushTransport
{
    return new FcmPushTransport(array_merge([
        'project_id' => 'demo', 'access_token' => 'tkn',
        'endpoint' => 'https://fcm.googleapis.com/v1/projects/{project}/messages:send',
    ], $over));
}

test('FCM envía el mensaje HTTP v1 con el token de acceso', function () {
    Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo/messages/1'])]);

    $result = fcm()->send(new PushMessage('device-token', 'T', 'B', ['k' => 'v']));

    expect($result->id)->toBe('projects/demo/messages/1');
    Http::assertSent(fn ($r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/demo/messages:send'
        && $r->hasHeader('Authorization', 'Bearer tkn')
        && $r['message']['token'] === 'device-token'
        && $r['message']['notification']['title'] === 'T');
});

test('FCM traduce los errores HTTP a errores tipados', function (int $status, string $class) {
    Http::fake(['*' => Http::response(['error' => 'x'], $status)]);

    expect(fn () => fcm()->send(new PushMessage('t', 'T', 'B')))->toThrow($class);
})->with([
    'token inexistente' => [404, PushTokenInvalidError::class],
    'rate limit' => [429, RateLimitedError::class],
    'servidor' => [503, TransientIntegrationError::class],
    'petición inválida' => [400, PermanentIntegrationError::class],
]);

test('FCM sin proyecto o sin credenciales falla de forma permanente', function () {
    expect(fn () => fcm(['project_id' => ''])->send(new PushMessage('t', 'T', 'B')))->toThrow(PermanentIntegrationError::class);
    expect(fn () => fcm(['access_token' => null, 'credentials_path' => '/no/existe.json'])->send(new PushMessage('t', 'T', 'B')))->toThrow(PermanentIntegrationError::class);
});

test('FCM con cuenta de servicio cambia un JWT firmado por un token de acceso y lo cachea', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, json_encode(['client_email' => 'svc@demo.iam', 'private_key' => $pem]));

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'oauth-1', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'm/1']),
    ]);
    $transport = fcm(['access_token' => null, 'credentials_path' => $path, 'token_url' => 'https://oauth2.googleapis.com/token']);

    $transport->send(new PushMessage('t', 'T', 'B'));
    $transport->send(new PushMessage('t', 'T', 'B'));
    unlink($path);

    Http::assertSentCount(3); // 1 token + 2 envíos
    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2') && substr_count((string) $r['assertion'], '.') === 2);
});

<?php

namespace App\Integrations\Push;

use App\Contracts\PushTransportInterface;
use App\Integrations\Exceptions\InvalidOutputError;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\Exceptions\PushTokenInvalidError;
use App\Integrations\Exceptions\TransientIntegrationError;
use App\Integrations\IntegrationHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/**
 * Firebase Cloud Messaging, API HTTP v1, sin SDK. Autenticación:
 *   - `FCM_ACCESS_TOKEN` (un token OAuth ya emitido; útil en pruebas), o
 *   - cuenta de servicio (`FCM_CREDENTIALS_PATH`, JSON): se firma un JWT RS256 con openssl, se cambia por un
 *     token de acceso y se cachea hasta poco antes de caducar.
 *
 * No reintenta: lanza errores tipados (docs/integrations.md §4). Un token que FCM da por inexistente
 * (404 / UNREGISTERED) lanza `PushTokenInvalidError`.
 */
class FcmPushTransport implements PushTransportInterface
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array<string, mixed>  $config  `integrations.push.fcm`
     */
    public function __construct(private readonly array $config) {}

    public function send(PushMessage $message): PushResult
    {
        $project = (string) ($this->config['project_id'] ?? '');

        if ($project === '') {
            throw new PermanentIntegrationError('FCM_PROJECT_ID no está configurado', 'push');
        }

        $url = str_replace('{project}', rawurlencode($project), (string) $this->config['endpoint']);

        $body = ['message' => [
            'token' => $message->token,
            'notification' => ['title' => $message->title, 'body' => $message->body],
            'data' => (object) $message->data,
        ]];

        try {
            $response = IntegrationHttp::client('push')->withToken($this->accessToken())->post($url, $body);
        } catch (ConnectionException $e) {
            throw new TransientIntegrationError('Error de conexión con FCM', 'push', $e);
        }

        if ($response->status() === 404 || str_contains((string) $response->body(), 'UNREGISTERED')) {
            throw new PushTokenInvalidError('FCM: token no registrado', 'push');
        }

        if (! $response->successful()) {
            throw IntegrationHttp::errorFor('push', $response);
        }

        $name = $response->json('name');

        if (! is_string($name) || $name === '') {
            throw new InvalidOutputError('FCM no devolvió el id del mensaje', 'push');
        }

        return new PushResult($name, 'fcm');
    }

    private function accessToken(): string
    {
        if (! empty($this->config['access_token'])) {
            return (string) $this->config['access_token'];
        }

        $path = (string) ($this->config['credentials_path'] ?? '');

        if ($path === '' || ! is_file($path)) {
            throw new PermanentIntegrationError('FCM_CREDENTIALS_PATH no apunta a una cuenta de servicio', 'push');
        }

        /** @var array<string, mixed>|null $credentials */
        $credentials = json_decode((string) file_get_contents($path), true);

        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new PermanentIntegrationError('La cuenta de servicio de FCM no es válida', 'push');
        }

        $key = 'push:fcm:token:'.sha1((string) $credentials['client_email']);

        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tokenUrl = (string) ($this->config['token_url'] ?? 'https://oauth2.googleapis.com/token');

        try {
            $response = IntegrationHttp::client('push')->asForm()->post($tokenUrl, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->signedJwt($credentials, $tokenUrl),
            ]);
        } catch (ConnectionException $e) {
            throw new TransientIntegrationError('Error de conexión con Google OAuth', 'push', $e);
        }

        if (! $response->successful()) {
            throw IntegrationHttp::errorFor('push', $response);
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new InvalidOutputError('Google OAuth no devolvió un token de acceso', 'push');
        }

        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 120));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function signedJwt(array $credentials, string $audience): string
    {
        $now = time();
        $segments = [
            $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->b64(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $audience,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR)),
        ];

        $signature = '';

        if (! openssl_sign(implode('.', $segments), $signature, (string) $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new PermanentIntegrationError('No se pudo firmar el JWT de FCM', 'push');
        }

        return implode('.', $segments).'.'.$this->b64($signature);
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

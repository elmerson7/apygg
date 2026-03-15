<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FcmService
 *
 * Envío de notificaciones push via FCM HTTP v1 API.
 * Soporta envío individual, multicast e invalidación de tokens.
 */
class FcmService
{
    protected static string $fcmUrl = 'https://fcm.googleapis.com/v1/projects/{project_id}/messages:send';

    /**
     * Obtener access token OAuth2 para FCM HTTP v1
     */
    protected static function getAccessToken(): string
    {
        $credentialsPath = config('services.fcm.credentials_path');

        if (! $credentialsPath || ! file_exists($credentialsPath)) {
            throw new \RuntimeException('FCM credentials file not found: '.$credentialsPath);
        }

        $credentials = json_decode(file_get_contents($credentialsPath), true);

        $now = time();
        $payload = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        // Crear JWT firmado con la clave privada
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = base64_encode(json_encode($payload));
        $toSign = $header.'.'.$claims;

        openssl_sign($toSign, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $toSign.'.'.base64_encode($signature);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to get FCM access token: '.$response->body());
        }

        return $response->json('access_token');
    }

    /**
     * Enviar notificación a un token FCM.
     */
    public static function send(
        string $token,
        string $title,
        string $body,
        array $data = [],
        array $extra = []
    ): bool {
        try {
            $projectId = config('services.fcm.project_id');
            $url = str_replace('{project_id}', $projectId, self::$fcmUrl);
            $accessToken = self::getAccessToken();

            $message = array_merge([
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
            ], $extra);

            $response = Http::withToken($accessToken)
                ->post($url, ['message' => $message]);

            if ($response->status() === 404) {
                // Token inválido/expirado — invalidar
                self::invalidateToken($token);

                return false;
            }

            if (! $response->successful()) {
                Log::warning('FCM send failed', [
                    'token' => substr($token, 0, 20).'...',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('FCM send error', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Enviar a todos los tokens de un usuario.
     */
    public static function sendToUser(User $user, string $title, string $body, array $data = []): array
    {
        $tokens = $user->deviceTokens()->pluck('token')->toArray();

        $results = ['sent' => 0, 'failed' => 0];

        foreach ($tokens as $token) {
            self::send($token, $title, $body, $data)
                ? $results['sent']++
                : $results['failed']++;
        }

        return $results;
    }

    /**
     * Invalida y elimina un token FCM de la BD.
     */
    public static function invalidateToken(string $token): void
    {
        \App\Models\DeviceToken::where('token', $token)->delete();

        Log::info('FCM token invalidated', ['token' => substr($token, 0, 20).'...']);
    }
}

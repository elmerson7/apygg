<?php

namespace App\Integrations\Push;

/** Mensaje push para un token. `data` son pares clave => texto (FCM solo admite cadenas). */
final readonly class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $token,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    /** Token recortado para logs: nunca el token completo. */
    public static function mask(string $token): string
    {
        return strlen($token) <= 8 ? '***' : substr($token, 0, 4).'...'.substr($token, -4);
    }
}

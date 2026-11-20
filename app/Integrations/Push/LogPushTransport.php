<?php

namespace App\Integrations\Push;

use App\Contracts\PushTransportInterface;
use Illuminate\Support\Facades\Log;

/** `PUSH_MODE=real PUSH_DRIVER=log`: solo deja una línea en el log (sin texto ni token completo). */
class LogPushTransport implements PushTransportInterface
{
    public function send(PushMessage $message): PushResult
    {
        Log::info('push: envío simulado por log', ['token' => PushMessage::mask($message->token)]);

        return new PushResult('log-'.bin2hex(random_bytes(6)), 'log');
    }
}

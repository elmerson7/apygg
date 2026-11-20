<?php

namespace App\Integrations\Sms;

use App\Contracts\SmsSenderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Modo `real` con `SMS_DRIVER=log`: no envía nada, solo deja una línea en el log.
 * Nunca escribe el texto (puede llevar un código de verificación) ni el número completo.
 */
class LogSmsSender implements SmsSenderInterface
{
    public function send(SmsMessage $message): SmsResult
    {
        Log::info('SMS (driver log, no enviado)', [
            'to' => $message->maskedTo(),
            'chars' => mb_strlen($message->body),
            'idempotency_key' => $message->idempotencyKey,
        ]);

        return new SmsResult('log-'.substr($message->idempotencyKey, 0, 12), 'logged', 'log');
    }
}

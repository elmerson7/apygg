<?php

namespace App\Services\Mail;

use App\Contracts\MailEventStatusServiceInterface;
use App\Models\Mail\MailEvent;
use App\Models\Mail\MailLog;

/**
 * MailEventStatusService
 *
 * Traduce los eventos del proveedor al estado del `MailLog`.
 *
 * Los eventos no llegan necesariamente en orden, así que el estado no se
 * deduce del último evento recibido sino del más reciente por fecha de
 * ocurrencia, y solo entre los del envío vigente (`provider_message_id`
 * actual): si un correo se reenvía, los eventos del intento anterior ya no
 * deben mover el estado.
 */
class MailEventStatusService implements MailEventStatusServiceInterface
{
    /**
     * Mapa evento del proveedor → estado del log.
     *
     * @var array<string, string>
     */
    private const TYPE_MAP = [
        'email.sent' => MailLog::STATUS_SENT,
        'email.delivered' => MailLog::STATUS_DELIVERED,
        'email.delivery_delayed' => MailLog::STATUS_SENT,
        'email.opened' => MailLog::STATUS_DELIVERED,
        'email.clicked' => MailLog::STATUS_DELIVERED,
        'email.bounced' => MailLog::STATUS_BOUNCED,
        'email.complained' => MailLog::STATUS_BOUNCED,
        'email.failed' => MailLog::STATUS_FAILED,
        'email.attempt_failed' => MailLog::STATUS_FAILED,
    ];

    /**
     * Estado correspondiente a un tipo de evento, o null si es desconocido.
     */
    public function mapType(string $type): ?string
    {
        return self::TYPE_MAP[$type] ?? null;
    }

    /**
     * Estado que le corresponde al log según sus eventos.
     */
    public function resolveStatusForLog(MailLog $log): ?string
    {
        $latest = MailEvent::query()
            ->where('mail_log_id', $log->id)
            ->where('provider_message_id', $log->provider_message_id)
            ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
            ->first();

        return $latest ? $this->mapType($latest->type) : null;
    }

    /**
     * Sincronizar el estado del log con sus eventos. Devuelve true si cambió.
     */
    public function syncStatus(MailLog $log): bool
    {
        $status = $this->resolveStatusForLog($log);

        if ($status === null || $status === $log->status) {
            return false;
        }

        $log->update(['status' => $status]);

        return true;
    }
}

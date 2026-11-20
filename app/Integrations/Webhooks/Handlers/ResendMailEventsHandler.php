<?php

namespace App\Integrations\Webhooks\Handlers;

use App\Contracts\InboundWebhookHandlerInterface;
use App\Contracts\MailEventStatusServiceInterface;
use App\Contracts\MailSuppressionServiceInterface;
use App\Models\InboundWebhookReceipt;
use App\Models\Mail\MailEvent;
use App\Models\Mail\MailLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Eventos de entrega de Resend (entregado, abierto, rebotado, spam...): guarda el `MailEvent`
 * y actualiza el estado del `MailLog`; un rebote permanente o una queja además suprimen la dirección. Un evento sin `MailLog` se guarda igualmente (diagnóstico).
 * Idempotente: `MailEvent::recordOnce` por `provider_event_id` (= id de entrega Svix).
 */
class ResendMailEventsHandler implements InboundWebhookHandlerInterface
{
    public function __construct(private readonly MailEventStatusServiceInterface $statusService,
        private readonly MailSuppressionServiceInterface $suppressions,
    ) {}

    public function handle(InboundWebhookReceipt $receipt): void
    {
        $payload = $receipt->payload;
        $type = (string) ($payload['type'] ?? '');
        $messageId = (string) data_get($payload, 'data.email_id', '');

        $log = MailLog::where('provider_message_id', $messageId)->first();
        $occurredAt = data_get($payload, 'data.timestamp') ?? ($payload['created_at'] ?? null);

        $event = MailEvent::recordOnce([
            'mail_log_id' => $log?->id,
            'provider_message_id' => $messageId,
            'provider_event_id' => $receipt->delivery_id,
            'type' => $type,
            'payload' => $payload,
            'occurred_at' => $occurredAt ? Carbon::parse($occurredAt) : null,
        ]);

        // Un evento repetido no vuelve a procesarse (ya suprimió la primera vez).
        if ($event === null) {
            return;
        }

        // Rebote permanente o queja: la dirección deja de recibir correo, haya o no `MailLog` (el correo
        // pudo salir antes de existir el registro o desde otro entorno).
        if ($this->suppressions->suppressFromProviderEvent($payload, 'resend') !== []) {
            Log::warning('Resend: dirección suprimida', ['type' => $type, 'mail_log_id' => $log?->id]);
        }

        if (! $log) {
            return;
        }

        if ($this->statusService->syncStatus($log)) {
            Log::info('Resend: estado de correo actualizado', ['mail_log_id' => $log->id, 'type' => $type]);
        }
    }
}

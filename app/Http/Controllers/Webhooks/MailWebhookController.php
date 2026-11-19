<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\MailEventStatusServiceInterface;
use App\Contracts\WebhookSignatureVerifierInterface;
use App\Models\Mail\MailEvent;
use App\Models\Mail\MailLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MailWebhookController
 *
 * Recibe los eventos del proveedor de correo (entregado, abierto, rebotado,
 * marcado como spam) y actualiza el estado del `MailLog` correspondiente.
 *
 * Sin esto, un correo figura como "enviado" para siempre aunque haya rebotado.
 *
 * Notas de implementación:
 * - La firma se verifica SIEMPRE; sin secreto configurado, se rechaza.
 * - Los eventos son idempotentes por `provider_event_id` (restricción única, sin
 *   comprobación previa): los proveedores reintentan y el mismo evento puede llegar
 *   varias veces, incluso a la vez.
 * - Un evento cuyo `MailLog` no se encuentra se guarda igual: sirve para
 *   diagnosticar y no se pierde información.
 * - Se responde 200 en casos "no accionables" (duplicado, log inexistente)
 *   para que el proveedor no reintente indefinidamente.
 */
class MailWebhookController
{
    public function __construct(
        private readonly MailEventStatusServiceInterface $statusService,
        private readonly WebhookSignatureVerifierInterface $verifier,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = config('services.resend.webhook_secret');

        if (! $secret) {
            Log::error('MailWebhook: secreto de webhook no configurado');

            return response()->json(['message' => 'Webhook no configurado'], 500);
        }

        if (! $this->verifier->verify($request, $secret)) {
            Log::warning('MailWebhook: firma inválida', [
                'svix_id' => $request->header('svix-id'),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Firma inválida'], 401);
        }

        $payload = $request->json()->all();
        $type = $payload['type'] ?? null;
        $messageId = $payload['data']['email_id'] ?? null;

        if (! $type || ! $messageId) {
            Log::warning('MailWebhook: payload con estructura inesperada', ['payload' => $payload]);

            return response()->json(['message' => 'Payload inválido'], 422);
        }

        $eventId = $request->header('svix-id');

        $log = MailLog::where('provider_message_id', $messageId)->first();
        $occurredAt = $payload['data']['timestamp'] ?? $payload['created_at'] ?? null;

        // Idempotencia ATÓMICA: sin comprobación previa (exists + create deja una ventana en la
        // que dos entregas iguales pasan a la vez); la restricción única decide y el perdedor
        // responde 200 como cualquier duplicado.
        $event = MailEvent::recordOnce([
            'mail_log_id' => $log?->id,
            'provider_message_id' => $messageId,
            'provider_event_id' => $eventId,
            'type' => $type,
            'payload' => $payload,
            'occurred_at' => $occurredAt ? Carbon::parse($occurredAt) : null,
        ]);

        if ($event === null) {
            return response()->json(['message' => 'Evento duplicado, ignorado']);
        }

        if (! $log) {
            Log::info('MailWebhook: evento sin MailLog asociado, guardado igualmente', [
                'message_id' => $messageId,
                'type' => $type,
            ]);

            return response()->json(['message' => 'OK']);
        }

        if ($this->statusService->syncStatus($log)) {
            Log::info('MailWebhook: estado actualizado', [
                'mail_log_id' => $log->id,
                'type' => $type,
                'status' => $log->fresh()?->status,
            ]);
        }

        return response()->json(['message' => 'OK']);
    }
}

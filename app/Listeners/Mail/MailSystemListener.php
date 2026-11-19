<?php

namespace App\Listeners\Mail;

use App\Contracts\Mail\HasMailTemplate;
use App\Mail\Transport\AccountsTransport;
use App\Models\Mail\MailAccount;
use App\Models\Mail\MailLog;
use App\Services\Mail\MailSendPolicy;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * MailSystemListener
 *
 * Engancha las capacidades del sistema de correo (pausa, límite diario, cuenta
 * desde la BD, registro en `mail_logs`) a los eventos estándar de Laravel, de
 * modo que valen para CUALQUIER Mailable o Notification sin camino paralelo.
 *
 *   MessageSending → aplica la política; si deniega registra el intento como
 *                    `skipped` y devuelve false (Laravel cancela el envío);
 *                    si no, elige la cuenta y crea el registro `pending`.
 *   MessageSent    → marca el registro `sent` con el id del proveedor.
 *
 * El fallo de envío lo marca `AccountsTransport` (no hay evento de fallo).
 */
class MailSystemListener
{
    public function __construct(private readonly MailSendPolicy $policy) {}

    public function handleSending(MessageSending $event): ?bool
    {
        $message = $event->message;
        $headers = $message->getHeaders();

        $templateKey = $headers->has(AccountsTransport::HEADER_TEMPLATE)
            ? $headers->get(AccountsTransport::HEADER_TEMPLATE)?->getBodyAsString()
            : $this->templateKeyFromClass($event->data);

        if ($templateKey && ! $headers->has(AccountsTransport::HEADER_TEMPLATE)) {
            $headers->addTextHeader(AccountsTransport::HEADER_TEMPLATE, $templateKey);
        }

        $reason = $this->policy->denialReason($templateKey);

        // Cuenta forzada por quien envía (p. ej. "correo de prueba" de una
        // cuenta concreta); si no, la de la plantilla / predeterminada.
        $forcedId = $headers->has(AccountsTransport::HEADER_ACCOUNT)
            ? (int) $headers->get(AccountsTransport::HEADER_ACCOUNT)?->getBodyAsString()
            : null;
        $account = $forcedId
            ? MailAccount::query()->find($forcedId)
            : MailAccount::getActiveForSending($templateKey);

        $log = $this->createLog($message, $event->data, $account, $templateKey, $reason);

        if ($reason !== null) {
            Log::warning('Correo cancelado', [
                'reason' => $reason,
                'template' => $templateKey,
                'to' => array_map(fn (Address $a) => $a->getAddress(), $message->getTo()),
            ]);

            return false;
        }

        if ($account) {
            $headers->remove(AccountsTransport::HEADER_ACCOUNT);
            $headers->addTextHeader(AccountsTransport::HEADER_ACCOUNT, (string) $account->id);

            // El remitente por defecto de la app se sustituye por el de la
            // cuenta; si el Mailable fijó uno propio, se respeta.
            $from = $message->getFrom()[0] ?? null;

            if (! $from || $from->getAddress() === config('mail.from.address')) {
                $message->from(new Address($account->from_email, (string) $account->from_name));
            }
        }

        if ($log) {
            $headers->remove(AccountsTransport::HEADER_LOG);
            $headers->addTextHeader(AccountsTransport::HEADER_LOG, (string) $log->id);
        }

        return null;
    }

    public function handleSent(MessageSent $event): void
    {
        $headers = $event->message->getHeaders();

        if (! $headers->has(AccountsTransport::HEADER_LOG)) {
            return;
        }

        $logId = (int) $headers->get(AccountsTransport::HEADER_LOG)?->getBodyAsString();

        $values = [
            'status' => MailLog::STATUS_SENT,
            'sent_at' => now(),
            'error_message' => null,
        ];

        if ($messageId = $event->sent->getMessageId()) {
            $values['provider_message_id'] = $messageId;
        }

        MailLog::query()->whereKey($logId)->update($values);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function templateKeyFromClass(array $data): ?string
    {
        $class = $data['__laravel_notification'] ?? $data['__laravel_mailable'] ?? null;

        if (is_string($class) && is_a($class, HasMailTemplate::class, true)) {
            return $class::mailTemplateKey();
        }

        return null;
    }

    /**
     * Un fallo al registrar no debe impedir el correo.
     *
     * Los datos de la vista NO se guardan (pueden traer tokens): solo la
     * clase que originó el correo.
     *
     * @param  array<string, mixed>  $data
     */
    private function createLog(
        Email $message,
        array $data,
        ?MailAccount $account,
        ?string $templateKey,
        ?string $skipReason,
    ): ?MailLog {
        try {
            $to = $message->getTo();
            $first = array_values($to)[0] ?? null;

            return MailLog::create([
                'mail_account_id' => $account?->id,
                'template_key' => $templateKey,
                'to_email' => $first?->getAddress() ?? '',
                'to_name' => ($first?->getName() ?? '') ?: null,
                'subject' => (string) $message->getSubject(),
                'status' => $skipReason === null ? MailLog::STATUS_PENDING : MailLog::STATUS_SKIPPED,
                'error_message' => $skipReason,
                'metadata' => array_filter([
                    'origin' => $data['__laravel_notification'] ?? $data['__laravel_mailable'] ?? null,
                    'recipients' => count($to) > 1
                        ? array_map(fn (Address $a) => $a->getAddress(), $to)
                        : null,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('No se pudo crear el registro de correo', ['error' => $e->getMessage()]);

            return null;
        }
    }
}

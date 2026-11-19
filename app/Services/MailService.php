<?php

namespace App\Services;

use App\Mail\SimpleMail;
use App\Models\Mail\MailAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MailService
 *
 * Fachada fina para enviar una VISTA Blade sin escribir un Mailable. Todo lo
 * demás (cuenta, pausa, límites, registro en `mail_logs`) NO ocurre aquí sino
 * en el sistema de correo de Laravel (listener de `MessageSending`, transport
 * `accounts`), así que vale igual para Mailables y Notifications propios.
 *
 * Para un correo de la app, prefiere un Mailable o una Notification
 * (ver docs/mail-system.md).
 *
 *   MailService::send('ana@ejemplo.com', 'Hola', 'emails.test', $data, 'mi_plantilla');
 */
class MailService
{
    /**
     * Enviar un correo (encolado por defecto).
     *
     * Con `queue: false` devuelve si el envío se realizó; encolado devuelve
     * true al aceptarlo (la pausa y el límite se aplican al enviar de verdad).
     *
     * @param  string|array{email: string, name?: string}|array<int, string|array{email: string, name?: string}>  $to
     * @param  array<string, mixed>  $data  Datos para la vista
     * @param  string|null  $templateKey  Clave de `mail_template_configs`
     * @param  MailAccount|null  $account  Fuerza esta cuenta de envío
     * @param  array<int, array{content: string, filename: string}>  $attachments
     */
    public static function send(
        string|array $to,
        string $subject,
        string $view,
        array $data = [],
        ?string $templateKey = null,
        bool $queue = true,
        ?MailAccount $account = null,
        array $attachments = [],
        ?int $delaySeconds = null,
    ): bool {
        $accepted = false;

        // Un Mailable por destinatario: un registro en `mail_logs` por cada uno.
        foreach (self::normalizeRecipients($to) as $recipient) {
            $mailable = new SimpleMail($view, $data, $subject, $templateKey, $account?->id, $attachments);
            $pending = Mail::to([['email' => $recipient['email'], 'name' => $recipient['name']]]);

            try {
                if (! $queue) {
                    $accepted = $pending->sendNow($mailable) !== null || $accepted;
                } elseif ($delaySeconds !== null && $delaySeconds > 0) {
                    $pending->later(now()->addSeconds($delaySeconds), $mailable);
                    $accepted = true;
                } else {
                    $pending->queue($mailable);
                    $accepted = true;
                }
            } catch (\Throwable $e) {
                Log::error('MailService: error de envío', [
                    'to' => $recipient['email'],
                    'subject' => $subject,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $accepted;
    }

    /**
     * Normalizar los destinatarios a `[['email' => ..., 'name' => ...], ...]`.
     *
     * @param  string|array<mixed>  $to
     * @return array<int, array{email: string, name: string|null}>
     */
    protected static function normalizeRecipients(string|array $to): array
    {
        // Un array con clave 'email' es UN destinatario, no una lista.
        $list = is_array($to) && ! array_key_exists('email', $to) ? $to : [$to];
        $recipients = [];

        foreach ($list as $item) {
            if (is_array($item)) {
                if (empty($item['email'])) {
                    continue;
                }

                $recipients[] = ['email' => $item['email'], 'name' => $item['name'] ?? null];

                continue;
            }

            $recipients[] = ['email' => $item, 'name' => null];
        }

        return $recipients;
    }
}

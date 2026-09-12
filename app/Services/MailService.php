<?php

namespace App\Services;

use App\Jobs\SendMailJob;
use App\Mail\SimpleMail;
use App\Models\Mail\MailAccount;
use App\Models\Mail\MailLog;
use App\Models\Mail\MailSetting;
use App\Models\Mail\MailTemplateConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MailService
 *
 * Punto de entrada único para enviar correo. Ningún módulo debe llamar a
 * `Mail::send()` directamente: pasando por aquí se obtiene, sin esfuerzo,
 *
 *   - selección de la cuenta de envío según la plantilla,
 *   - respeto de la pausa global y de los límites de envío,
 *   - registro en `mail_logs` de cada intento,
 *   - envío asíncrono con reintentos, y
 *   - correlación posterior con los webhooks del proveedor.
 *
 * Uso básico:
 *   MailService::send('ana@ejemplo.com', 'Bienvenida', 'emails.welcome', $data, 'welcome');
 */
class MailService
{
    /**
     * Enviar un correo (encolado por defecto).
     *
     * @param  string|array<int, string|array{email: string, name?: string}>  $to
     * @param  array<string, mixed>  $data  Datos para la vista
     * @param  string|null  $templateKey  Clave de `mail_template_configs`
     * @param  array<int, array{content: string, filename: string}>  $attachments
     * @return bool true si se aceptó para envío
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
        ?string $recipientType = null,
    ): bool {
        if (! self::canSend($templateKey, $to)) {
            return false;
        }

        $account ??= MailAccount::getActiveForSending($templateKey);

        if (! $account) {
            Log::error('MailService: no hay cuenta de correo disponible', [
                'to' => $to,
                'template' => $templateKey,
            ]);

            return false;
        }

        $sentAny = false;

        foreach (self::normalizeRecipients($to) as $recipient) {
            $log = self::createLog($account, $templateKey, $recipient, $subject, $recipientType, $data);

            if ($queue) {
                $job = SendMailJob::dispatch(
                    $recipient['email'],
                    $recipient['name'],
                    $subject,
                    $view,
                    $data,
                    $templateKey,
                    $account->id,
                    $log?->id,
                    $attachments,
                );

                if ($delaySeconds !== null && $delaySeconds > 0) {
                    $job->delay(now()->addSeconds($delaySeconds));

                    $log?->update([
                        'scheduled_at' => now()->addSeconds($delaySeconds),
                        'status' => MailLog::STATUS_SCHEDULED,
                    ]);
                }

                $sentAny = true;

                continue;
            }

            $sentAny = self::sendNow($account, $recipient, $subject, $view, $data, $log) || $sentAny;
        }

        return $sentAny;
    }

    /**
     * ¿Se puede enviar ahora mismo? Pausa global, plantilla pausada y límite diario.
     */
    protected static function canSend(?string $templateKey, string|array $to): bool
    {
        if (MailSetting::isGloballyPaused()) {
            Log::warning('MailService: envío omitido, pausa global activa', [
                'to' => $to,
                'template' => $templateKey,
            ]);

            return false;
        }

        if ($templateKey && MailTemplateConfig::isPaused($templateKey)) {
            Log::info('MailService: envío omitido, plantilla pausada', [
                'to' => $to,
                'template' => $templateKey,
            ]);

            return false;
        }

        $dailyLimit = (int) MailSetting::getValue(MailSetting::KEY_DAILY_LIMIT, 0);

        if ($dailyLimit > 0 && MailLog::sentToday() >= $dailyLimit) {
            Log::warning('MailService: envío omitido, límite diario alcanzado', [
                'limit' => $dailyLimit,
                'to' => $to,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Envío síncrono. Úsalo solo cuando necesites saber el resultado en el acto
     * (por ejemplo, un "enviar correo de prueba" del panel).
     *
     * @param  array{email: string, name: string|null}  $recipient
     * @param  array<string, mixed>  $data
     */
    protected static function sendNow(
        MailAccount $account,
        array $recipient,
        string $subject,
        string $view,
        array $data,
        ?MailLog $log,
    ): bool {
        try {
            $mailable = new SimpleMail($view, $data, $subject);
            $mailable->from($account->from_email, $account->from_name);

            Mail::mailer($account->getDriver())
                ->to($recipient['email'], $recipient['name'])
                ->send($mailable);

            $log?->update([
                'status' => MailLog::STATUS_SENT,
                'sent_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('MailService: error en envío síncrono', [
                'to' => $recipient['email'],
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            $log?->update([
                'status' => MailLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Normalizar los destinatarios a `[['email' => ..., 'name' => ...], ...]`.
     *
     * @param  string|array<int, string|array{email: string, name?: string}>  $to
     * @return array<int, array{email: string, name: string|null}>
     */
    protected static function normalizeRecipients(string|array $to): array
    {
        $list = is_array($to) && ! isset($to['email']) ? $to : [$to];
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

    /**
     * @param  array{email: string, name: string|null}  $recipient
     * @param  array<string, mixed>  $data
     */
    protected static function createLog(
        MailAccount $account,
        ?string $templateKey,
        array $recipient,
        string $subject,
        ?string $recipientType,
        array $data,
    ): ?MailLog {
        try {
            return MailLog::create([
                'mail_account_id' => $account->id,
                'template_key' => $templateKey,
                'to_email' => $recipient['email'],
                'to_name' => $recipient['name'],
                'recipient_type' => $recipientType,
                'subject' => $subject,
                'status' => MailLog::STATUS_PENDING,
                'metadata' => self::sanitizeMetadata($data),
            ]);
        } catch (\Throwable $e) {
            // Un fallo al registrar no debe impedir el envío del correo.
            Log::error('MailService: no se pudo crear el registro de correo', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Los datos de la vista pueden traer tokens, contraseñas temporales o
     * adjuntos en base64. Nada de eso debe acabar en la BD.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function sanitizeMetadata(array $data): array
    {
        $sensitive = ['password', 'token', 'reset_url', 'secret', 'api_key', 'content'];
        $clean = [];

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Atajos para los correos del boilerplate
    // ─────────────────────────────────────────────────────────────────────

    public static function sendWelcome(string $email, string $name): bool
    {
        return self::send(
            ['email' => $email, 'name' => $name],
            'Bienvenido a '.config('app.name'),
            'emails.welcome',
            ['name' => $name, 'app_name' => config('app.name'), 'app_url' => config('app.url')],
            'welcome',
        );
    }

    public static function sendPasswordReset(string $email, string $name, string $resetUrl): bool
    {
        return self::send(
            ['email' => $email, 'name' => $name],
            'Restablecer contraseña — '.config('app.name'),
            'emails.password-reset',
            [
                'name' => $name,
                'reset_url' => $resetUrl,
                'app_name' => config('app.name'),
                'expires_minutes' => 60,
            ],
            'password_reset',
        );
    }
}

<?php

namespace App\Services;

use App\Mail\SimpleMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MailService
 *
 * Servicio genérico de envío de emails vía Laravel Mailer.
 * En local/dev usa Mailpit (MAIL_MAILER=smtp, MAIL_HOST=mailpit, MAIL_PORT=1025).
 * Para producción cambiar MAIL_MAILER en .env (zoho, ses, etc.).
 */
class MailService
{
    /**
     * Enviar email a uno o varios destinatarios.
     *
     * @param  array|string  $to  Email o array de ['email', 'name']
     * @param  string  $subject  Asunto
     * @param  string  $view  Vista blade (ej: 'emails.welcome')
     * @param  array  $data  Datos para la vista
     */
    public static function send(array|string $to, string $subject, string $view, array $data = []): bool
    {
        try {
            $mailable = new SimpleMail($view, $data, $subject);

            $recipients = is_array($to) ? $to : [$to];

            foreach ($recipients as $recipient) {
                if (is_array($recipient)) {
                    Mail::to($recipient['email'], $recipient['name'] ?? null)->queue($mailable);
                } else {
                    Mail::to($recipient)->queue($mailable);
                }
            }

            Log::info('MailService: email encolado', ['to' => $to, 'subject' => $subject]);

            return true;
        } catch (\Exception $e) {
            Log::error('MailService: error al enviar', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enviar email de bienvenida al registrar usuario.
     */
    public static function sendWelcome(string $email, string $name): bool
    {
        return self::send(
            ['email' => $email, 'name' => $name],
            'Bienvenido a '.config('app.name'),
            'emails.welcome',
            ['name' => $name, 'app_name' => config('app.name'), 'app_url' => config('app.url')]
        );
    }

    /**
     * Enviar email de restablecimiento de contraseña.
     */
    public static function sendPasswordReset(string $email, string $name, string $resetUrl): bool
    {
        return self::send(
            ['email' => $email, 'name' => $name],
            'Restablecer contraseña — '.config('app.name'),
            'emails.password-reset',
            ['name' => $name, 'reset_url' => $resetUrl, 'app_name' => config('app.name'), 'expires_minutes' => 60]
        );
    }
}

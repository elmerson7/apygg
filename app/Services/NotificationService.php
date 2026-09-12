<?php

namespace App\Services;

use App\Models\NotificationHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * NotificationService
 *
 * Servicio centralizado para notificaciones multi-canal (email, SMS, push, database).
 */
class NotificationService
{
    /**
     * Canales disponibles
     */
    protected const CHANNELS = [
        'mail' => 'email',
        'database' => 'database',
        'sms' => 'sms',
        'push' => 'push',
    ];

    /**
     * Enviar notificación por email
     *
     * @param  string|array  $to  Email(s) destinatario(s)
     * @param  string  $subject  Asunto
     * @param  string  $view  Vista del email
     * @param  array  $data  Datos para la vista
     * @param  bool  $queue  Si debe enviarse en cola
     */
    /**
     * Enviar notificación por email.
     *
     * Delega en MailService para que el envío pase por el sistema de correo:
     * selección de cuenta, pausa global, plantillas y registro en mail_logs.
     * No envíes correo con Mail::send() directamente.
     *
     * @param  string|array<int, string|array{email: string, name?: string}>  $to
     * @param  array<string, mixed>  $data
     * @param  string|null  $templateKey  Clave de plantilla (permite pausarla desde el panel)
     */
    public static function sendEmail(
        string|array $to,
        string $subject,
        string $view,
        array $data = [],
        bool $queue = true,
        ?string $templateKey = null
    ): bool {
        $sent = MailService::send(
            to: $to,
            subject: $subject,
            view: $view,
            data: $data,
            templateKey: $templateKey,
            queue: $queue,
        );

        if ($sent) {
            self::logNotification('mail', $to, $subject);
        }

        return $sent;
    }

    /**
     * Enviar notificación a base de datos
     *
     * @param  string  $title  Título
     * @param  string  $message  Mensaje
     * @param  array  $data  Datos adicionales
     * @param  string|null  $type  Tipo de notificación
     */
    public static function sendDatabase(
        Authenticatable $notifiable,
        string $title,
        string $message,
        array $data = [],
        ?string $type = null
    ): bool {
        try {
            $notifiable->notify(new class($title, $message, $data, $type) extends Notification
            {
                public function __construct(
                    public string $title,
                    public string $message,
                    public array $data,
                    public ?string $type
                ) {}

                public function via($notifiable): array
                {
                    return ['database'];
                }

                public function toDatabase($notifiable): array
                {
                    return [
                        'title' => $this->title,
                        'message' => $this->message,
                        'type' => $this->type,
                        'data' => $this->data,
                    ];
                }
            });

            self::logNotification('database', $notifiable->id ?? 'unknown', $title);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send database notification', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enviar notificación SMS (requiere servicio externo)
     *
     * @param  string  $phone  Número de teléfono
     * @param  string  $message  Mensaje
     * @param  bool  $queue  Si debe enviarse en cola
     */
    public static function sendSms(string $phone, string $message, bool $queue = true): bool
    {
        try {
            // Implementar integración con servicio SMS (Twilio, AWS SNS, etc.)
            // Por ahora solo loguear
            Log::info('SMS notification queued', [
                'phone' => $phone,
                'message' => substr($message, 0, 50).'...',
            ]);

            if ($queue) {
                // Dispatch job para enviar SMS
                // SmsJob::dispatch($phone, $message);
            } else {
                // Enviar SMS inmediatamente
                // self::sendSmsImmediate($phone, $message);
            }

            self::logNotification('sms', $phone, 'SMS');

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send SMS notification', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enviar notificación push (requiere servicio externo)
     *
     * @param  string|array  $tokens  Token(s) del dispositivo
     * @param  string  $title  Título
     * @param  string  $message  Mensaje
     * @param  array  $data  Datos adicionales
     * @param  bool  $queue  Si debe enviarse en cola
     */
    public static function sendPush(
        string|array $tokens,
        string $title,
        string $message,
        array $data = [],
        bool $queue = true
    ): bool {
        try {
            // Implementar integración con servicio push (FCM, APNS, etc.)
            // Por ahora solo loguear
            Log::info('Push notification queued', [
                'tokens_count' => is_array($tokens) ? count($tokens) : 1,
                'title' => $title,
            ]);

            if ($queue) {
                // PushJob::dispatch($tokens, $title, $message, $data);
            } else {
                // self::sendPushImmediate($tokens, $title, $message, $data);
            }

            self::logNotification('push', is_array($tokens) ? count($tokens).' tokens' : $tokens, $title);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send push notification', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enviar notificación multi-canal
     *
     * @param  array  $channels  Canales a usar ['mail', 'database', 'sms', 'push']
     * @param  mixed  $notifiable  Usuario o email/phone
     * @param  string  $title  Título
     * @param  string  $message  Mensaje
     * @param  array  $data  Datos adicionales
     * @param  bool  $queue  Si debe enviarse en cola
     * @return array Resultados por canal
     */
    public static function sendMultiChannel(
        array $channels,
        $notifiable,
        string $title,
        string $message,
        array $data = [],
        bool $queue = true
    ): array {
        $results = [];

        foreach ($channels as $channel) {
            $results[$channel] = match ($channel) {
                'mail' => self::sendEmail(
                    is_object($notifiable) ? $notifiable->email : $notifiable,
                    $title,
                    'emails.notification',
                    array_merge($data, ['message' => $message]),
                    $queue
                ),
                'database' => self::sendDatabase($notifiable, $title, $message, $data),
                'sms' => self::sendSms(
                    is_object($notifiable) ? $notifiable->phone : $notifiable,
                    $message,
                    $queue
                ),
                'push' => self::sendPush(
                    is_object($notifiable) ? $notifiable->push_tokens : [],
                    $title,
                    $message,
                    $data,
                    $queue
                ),
                default => false,
            };
        }

        return $results;
    }

    /**
     * Guardar historial de notificación
     */
    protected static function logNotification(string $channel, $recipient, string $subject): void
    {
        try {
            NotificationHistory::create([
                'channel' => $channel,
                'recipient' => is_array($recipient) ? json_encode($recipient) : $recipient,
                'subject' => $subject,
                'status' => NotificationHistory::STATUS_SENT,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // El historial nunca debe tumbar el envío que lo originó.
            Log::warning('NotificationService: no se pudo registrar el historial', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Obtener historial de notificaciones
     *
     * @param  string|null  $channel  Filtrar por canal
     * @param  int  $limit  Límite de resultados
     */
    public static function getHistory(?string $channel = null, int $limit = 50): Collection
    {
        $query = NotificationHistory::query()
            ->orderBy('sent_at', 'desc')
            ->limit($limit);

        if ($channel) {
            $query->where('channel', $channel);
        }

        return $query->get();
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Support\Facades\Log;

/**
 * NotificationService
 * 
 * Servicio centralizado para notificaciones multi-canal (email, SMS, push, database).
 * 
 * @package App\Services
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
     * @param string|array $to Email(s) destinatario(s)
     * @param string $subject Asunto
     * @param string $view Vista del email
     * @param array $data Datos para la vista
     * @param bool $queue Si debe enviarse en cola
     * @return bool
     */
    public static function sendEmail(
        string|array $to,
        string $subject,
        string $view,
        array $data = [],
        bool $queue = true
    ): bool {
        try {
            $emails = is_array($to) ? $to : [$to];

            if ($queue) {
                Mail::queue($view, $data, function ($message) use ($emails, $subject) {
                    $message->to($emails)->subject($subject);
                });
            } else {
                Mail::send($view, $data, function ($message) use ($emails, $subject) {
                    $message->to($emails)->subject($subject);
                });
            }

            self::logNotification('mail', $to, $subject);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send email notification', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Enviar notificación a base de datos
     *
     * @param \Illuminate\Notifications\Notifiable $notifiable
     * @param string $title Título
     * @param string $message Mensaje
     * @param array $data Datos adicionales
     * @param string|null $type Tipo de notificación
     * @return bool
     */
    public static function sendDatabase(
        $notifiable,
        string $title,
        string $message,
        array $data = [],
        ?string $type = null
    ): bool {
        try {
            $notifiable->notify(new class($title, $message, $data, $type) extends \Illuminate\Notifications\Notification {
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
     * @param string $phone Número de teléfono
     * @param string $message Mensaje
     * @param bool $queue Si debe enviarse en cola
     * @return bool
     */
    public static function sendSms(string $phone, string $message, bool $queue = true): bool
    {
        try {
            // Implementar integración con servicio SMS (Twilio, AWS SNS, etc.)
            // Por ahora solo loguear
            Log::info('SMS notification queued', [
                'phone' => $phone,
                'message' => substr($message, 0, 50) . '...',
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
     * @param string|array $tokens Token(s) del dispositivo
     * @param string $title Título
     * @param string $message Mensaje
     * @param array $data Datos adicionales
     * @param bool $queue Si debe enviarse en cola
     * @return bool
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

            self::logNotification('push', is_array($tokens) ? count($tokens) . ' tokens' : $tokens, $title);
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
     * @param array $channels Canales a usar ['mail', 'database', 'sms', 'push']
     * @param mixed $notifiable Usuario o email/phone
     * @param string $title Título
     * @param string $message Mensaje
     * @param array $data Datos adicionales
     * @param bool $queue Si debe enviarse en cola
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
            $results[$channel] = match($channel) {
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
            // Guardar en tabla de historial si existe
            if (class_exists(\App\Models\NotificationHistory::class)) {
                \App\Models\NotificationHistory::create([
                    'channel' => $channel,
                    'recipient' => is_array($recipient) ? json_encode($recipient) : $recipient,
                    'subject' => $subject,
                    'sent_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            // Silenciar errores
        }
    }

    /**
     * Obtener historial de notificaciones
     *
     * @param string|null $channel Filtrar por canal
     * @param int $limit Límite de resultados
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getHistory(?string $channel = null, int $limit = 50)
    {
        if (!class_exists(\App\Models\NotificationHistory::class)) {
            return collect([]);
        }

        $query = \App\Models\NotificationHistory::query()
            ->orderBy('sent_at', 'desc')
            ->limit($limit);

        if ($channel) {
            $query->where('channel', $channel);
        }

        return $query->get();
    }
}

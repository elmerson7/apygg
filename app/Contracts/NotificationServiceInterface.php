<?php

namespace App\Contracts;

use App\Models\NotificationHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

/**
 * NotificationServiceInterface
 *
 * Contrato del servicio de notificaciones multicanal.
 */
interface NotificationServiceInterface
{
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
    public function sendEmail(string|array $to,
        string $subject,
        string $view,
        array $data = [],
        bool $queue = true,
        ?string $templateKey = null): bool;

    /**
     * Enviar notificación a base de datos
     *
     * @param  string  $title  Título
     * @param  string  $message  Mensaje
     * @param  array<string, mixed>  $data  Datos adicionales
     * @param  string|null  $type  Tipo de notificación
     */
    public function sendDatabase(Authenticatable $notifiable,
        string $title,
        string $message,
        array $data = [],
        ?string $type = null): bool;

    /**
     * Enviar notificación SMS (requiere servicio externo)
     *
     * @param  string  $phone  Número de teléfono
     * @param  string  $message  Mensaje
     * @param  bool  $queue  Si debe enviarse en cola
     */
    public function sendSms(string $phone, string $message, bool $queue = true): bool;

    /**
     * Enviar notificación push (requiere servicio externo)
     *
     * @param  string|list<string>  $tokens  Token(s) del dispositivo
     * @param  string  $title  Título
     * @param  string  $message  Mensaje
     * @param  array<string, mixed>  $data  Datos adicionales
     * @param  bool  $queue  Si debe enviarse en cola
     */
    public function sendPush(string|array $tokens,
        string $title,
        string $message,
        array $data = [],
        bool $queue = true): bool;

    /**
     * Enviar notificación multi-canal
     *
     * @param  list<string>  $channels  Canales a usar ['mail', 'database', 'sms', 'push']
     * @param  mixed  $notifiable  Usuario o email/phone
     * @param  string  $title  Título
     * @param  string  $message  Mensaje
     * @param  array<string, mixed>  $data  Datos adicionales
     * @param  bool  $queue  Si debe enviarse en cola
     * @return array<string, mixed> Resultados por canal
     */
    public function sendMultiChannel(array $channels,
        $notifiable,
        string $title,
        string $message,
        array $data = [],
        bool $queue = true): array;

    /**
     * Obtener historial de notificaciones
     *
     * @param  string|null  $channel  Filtrar por canal
     * @param  int  $limit  Límite de resultados
     * @return Collection<int, NotificationHistory>
     */
    public function getHistory(?string $channel = null, int $limit = 50): Collection;
}

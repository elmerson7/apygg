<?php

namespace App\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;

/**
 * NotificationServiceInterface
 *
 * Contrato para el servicio de notificaciones multi-canal.
 */
interface NotificationServiceInterface
{
    /**
     * Enviar notificación por email
     *
     * @param  string|array  $to  Email(s) destinatario(s)
     * @param  string  $subject  Asunto
     * @param  string  $view  Vista del email
     * @param  array  $data  Datos para la vista
     * @param  bool  $queue  Si debe enviarse en cola
     * @return bool  True si se envió exitosamente
     */
    public function sendEmail(string|array $to, string $subject, string $view, array $data = [], bool $queue = true): bool;

    /**
     * Enviar notificación a base de datos
     *
     * @param  Authenticatable  $notifiable  Usuario notificable
     * @param  string  $title  Título
     * @param  string  $message  Mensaje
     * @param  array  $data  Datos adicionales
     * @param  string|null  $type  Tipo de notificación
     * @return bool  True si se envió exitosamente
     */
    public function sendDatabase(Authenticatable $notifiable, string $title, string $message, array $data = [], ?string $type = null): bool;

    /**
     * Enviar notificación SMS (requiere servicio externo)
     *
     * @param  string  $phone  Número de teléfono
     * @param  string  $message  Mensaje
     * @param  bool  $queue  Si debe enviarse en cola
     * @return bool  True si se envió exitosamente
     */
    public function sendSms(string $phone, string $message, bool $queue = true): bool;

    /**
     * Enviar notificación push (requiere servicio externo)
     *
     * @param  string|array  $tokens  Token(s) del dispositivo
     * @param  string  $title  Título
     * @param  string  $message  Mensaje
     * @param  array  $data  Datos adicionales
     * @param  bool  $queue  Si debe enviarse en cola
     * @return bool  True si se envió exitosamente
     */
    public function sendPush(string|array $tokens, string $title, string $message, array $data = [], bool $queue = true): bool;

    /**
     * Enviar notificación multi-canal
     *
     * @param  array  $channels  Canales a usar ['mail', 'database', 'sms', 'push']
     * @param  mixed  $notifiable  Usuario o email/phone
     * @param  string  $title  Título
     * @param  string  $message  Mensaje
     * @param  array  $data  Datos adicionales
     * @param  bool  $queue  Si debe enviarse en cola
     * @return array  Resultados por canal
     */
    public function sendMultiChannel(array $channels, $notifiable, string $title, string $message, array $data = [], bool $queue = true): array;

    /**
     * Obtener historial de notificaciones
     *
     * @param  string|null  $channel  Filtrar por canal
     * @param  int  $limit  Límite de resultados
     * @return Collection  Colección de notificaciones
     */
    public function getHistory(?string $channel = null, int $limit = 50): Collection;
}
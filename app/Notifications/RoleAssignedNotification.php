<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RoleAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $roleName,
        public readonly string $assignedBy,
        public readonly string $assignedAt
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nuevo rol asignado - ' . config('app.name'))
            ->greeting('Hola ' . $notifiable->name . '!')
            ->line('Se te ha asignado un nuevo rol en la plataforma.')
            ->line('Rol: ' . $this->roleName)
            ->line('Asignado por: ' . $this->assignedBy)
            ->line('Fecha: ' . $this->assignedAt)
            ->line('Si tienes preguntas sobre tus permisos, contacta al administrador.')
            ->salutation('Saludos, el equipo de ' . config('app.name'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'role_assigned',
            'role_name' => $this->roleName,
            'assigned_by' => $this->assignedBy,
            'assigned_at' => $this->assignedAt,
        ];
    }
}
<?php

namespace App\Notifications;

use App\Contracts\Mail\HasMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo de invitación a un tenant. Se envía a un destinatario "bajo demanda"
 * (`Notification::route('mail', $email)`: la persona puede no tener cuenta aún) por el camino único de
 * correo (docs/mail-system.md). El token en claro viaja SOLO aquí (y en el payload de la cola).
 */
class TenantInvitationNotification extends Notification implements HasMailTemplate, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly string $tenantName,
        private readonly string $inviterName,
        private readonly string $role,
        private readonly string $token,
        private readonly \DateTimeInterface $expiresAt,
    ) {
        $this->onQueue(config('mail-system.queue', 'default'));
        $this->afterCommit();
    }

    public static function mailTemplateKey(): string
    {
        return 'tenant_invitation';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function acceptUrl(): string
    {
        return str_replace('{token}', rawurlencode($this->token), (string) config('multi-tenancy.onboarding.invitations.accept_url'));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviterName} te invitó a {$this->tenantName}")
            ->greeting('Hola')
            ->line("{$this->inviterName} te invitó a unirte a «{$this->tenantName}» con el rol «{$this->role}».")
            ->action('Aceptar invitación', $this->acceptUrl())
            ->line('La invitación caduca el '.$this->expiresAt->format('Y-m-d H:i').' (UTC).')
            ->line('Si no esperabas este correo, ignóralo.');
    }
}

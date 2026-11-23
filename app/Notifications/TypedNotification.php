<?php

namespace App\Notifications;

use App\Contracts\Mail\HasMailTemplate;
use App\Integrations\Sms\SmsMessage;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * La notificación de los tipos declarados en `config/notifications.php` (docs/notifications.md).
 *
 * Es la ÚNICA clase que viaja a la cola: `via()` devuelve solo el canal `delivery` (`DeliveryChannel`),
 * que decide por usuario qué canales usar (preferencias, silencio, obligatorias), reclama cada entrega de
 * forma idempotente y la registra. Los textos salen de `lang/<idioma>/notifications.php`.
 */
class TypedNotification extends Notification implements HasMailTemplate, ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    private readonly ?int $tenantId;

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public readonly string $type,
        public readonly array $params = [],
    ) {
        $this->tries = max(1, (int) config('notifications.tries', 3));
        $this->backoff = array_values(array_map('intval', (array) config('notifications.backoff', [10, 60])));
        $this->tenantId = app(TenantContext::class)->id();

        $this->onQueue((string) config('notifications.queue', 'default'));
        $this->afterCommit();
    }

    public static function mailTemplateKey(): string
    {
        return 'notification';
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['delivery'];
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::find($this->type);
    }

    /** Tenant con el que se creó (se restaura en el worker aunque el Context no viaje). */
    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function title(): string
    {
        return $this->notificationType()->title(app('translator')->getLocale(), $this->params);
    }

    public function body(): string
    {
        return $this->notificationType()->body(app('translator')->getLocale(), $this->params);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting(__('notifications.greeting', ['name' => $notifiable instanceof User ? $notifiable->name : '']))
            ->line($this->body());

        if (! empty($this->params['action_url'])) {
            $defaultText = __('notifications.action');
            $text = $this->params['action_text'] ?? (is_string($defaultText) ? $defaultText : '');
            $mail->action((string) $text, (string) $this->params['action_url']);
        }

        return $mail;
    }

    /**
     * @return array{type: string, title: string, body: string, data: array<string, mixed>}
     */
    public function toInbox(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title(),
            'body' => $this->body(),
            'data' => ['params' => $this->params],
        ];
    }

    /**
     * @return array{title: string, body: string, data: array<string, string>}
     */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'data' => ['type' => $this->type] + array_map(fn ($v) => (string) $v, array_filter($this->params, fn ($v) => $v !== null)),
        ];
    }

    public function toSms(object $notifiable): SmsMessage|string
    {
        return $this->title().': '.$this->body();
    }
}

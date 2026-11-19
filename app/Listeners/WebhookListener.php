<?php

namespace App\Listeners;

use App\Contracts\WebhookServiceInterface;
use App\Events\PermissionGranted;
use App\Events\PermissionRevoked;
use App\Events\RoleAssigned;
use App\Events\RoleRemoved;
use App\Events\UserCreated;
use App\Events\UserDeleted;
use App\Events\UserLoggedIn;
use App\Events\UserLoggedOut;
use App\Events\UserRestored;
use App\Events\UserUpdated;
use App\Models\Webhook;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * WebhookListener
 *
 * Listener que captura eventos del sistema y dispara webhooks suscritos.
 * Se ejecuta en cola para no bloquear la respuesta HTTP.
 */
class WebhookListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly WebhookServiceInterface $webhookService) {}

    /**
     * Handle the event.
     *
     * @param  object  $event
     */
    public function handle($event): void
    {
        // Obtener nombre del evento webhook desde la clase del evento Laravel
        $eventName = Webhook::getEventName(get_class($event));

        if (! $eventName) {
            // Evento no mapeado, ignorar
            return;
        }

        // Multiempresa: los webhooks son de un tenant y solo reciben los eventos que ocurren DENTRO de
        // ese tenant. El listener corre en cola con el tenant con el que se disparó el evento (Context);
        // un evento sin tenant (login, tareas de plataforma) no se entrega a ningún webhook (falla cerrado).
        $tenancy = app(TenantContext::class);

        if ($tenancy->enabled() && $tenancy->id() === null) {
            return;
        }

        // Regla de ámbito: un webhook recibe SOLO los eventos de recursos de su dueño.
        // Los eventos de otros usuarios (logins, roles, altas...) nunca se le entregan.
        $ownerId = $this->ownerIdFor($event);

        if ($ownerId === null) {
            return;
        }

        $webhooks = $this->webhookService->getWebhooksForEvent($eventName, $ownerId);

        if ($webhooks->isEmpty()) {
            return;
        }

        // Preparar payload del evento
        $payload = $this->preparePayload($event, $eventName);

        // Enviar webhook a cada suscriptor
        foreach ($webhooks as $webhook) {
            if ($webhook->listensTo($eventName)) {
                $this->webhookService->send($webhook, $eventName, $payload);
            }
        }
    }

    /**
     * Dueño del recurso al que se refiere el evento: el usuario afectado.
     *
     * Con multiempresa activo se suma el tenant del contexto (los webhooks tienen `tenant_id` y el
     * scope filtra); aquí un proyecto puede afinar el ámbito; ver docs/webhooks.md. Sin dueño identificable el evento no se entrega a nadie.
     *
     * @param  mixed  $event
     */
    protected function ownerIdFor($event): int|string|null
    {
        return $event->user->id ?? null;
    }

    /**
     * Preparar payload desde el evento
     *
     * @param  mixed  $event
     * @return array<string, mixed>
     */
    protected function preparePayload($event, string $eventName): array
    {
        $payload = [
            'event' => $eventName,
            'timestamp' => now()->toIso8601String(),
        ];

        // Extraer datos específicos según el tipo de evento
        switch (true) {
            case $event instanceof UserCreated:
            case $event instanceof UserUpdated:
            case $event instanceof UserDeleted:
            case $event instanceof UserRestored:
                $payload['data'] = [
                    'user' => [
                        'id' => $event->user->id,
                        'name' => $event->user->name,
                        'email' => $event->user->email,
                    ],
                ];
                break;

            case $event instanceof UserLoggedIn:
            case $event instanceof UserLoggedOut:
                $payload['data'] = [
                    'user' => [
                        'id' => $event->user->id,
                        'name' => $event->user->name,
                        'email' => $event->user->email,
                    ],
                    'ip' => $event->ipAddress ?? null,
                    'user_agent' => $event->userAgent ?? null,
                ];
                break;

            case $event instanceof RoleAssigned:
            case $event instanceof RoleRemoved:
                $payload['data'] = [
                    'user' => [
                        'id' => $event->user->id,
                        'name' => $event->user->name,
                        'email' => $event->user->email,
                    ],
                    'role' => [
                        'id' => $event->role->id,
                        'name' => $event->role->name,
                        'display_name' => $event->role->display_name,
                    ],
                ];
                break;

            case $event instanceof PermissionGranted:
            case $event instanceof PermissionRevoked:
                $payload['data'] = [
                    'user' => [
                        'id' => $event->user->id,
                        'name' => $event->user->name,
                        'email' => $event->user->email,
                    ],
                    'permission' => [
                        'id' => $event->permission->id,
                        'name' => $event->permission->name,
                        'display_name' => $event->permission->display_name,
                    ],
                ];
                break;

            default:
                // Payload genérico
                $payload['data'] = [];
                break;
        }

        return $payload;
    }
}

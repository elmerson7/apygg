<?php

namespace App\Listeners;

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
use App\Services\WebhookService;
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

    /**
     * Handle the event.
     */
    public function handle($event): void
    {
        // Obtener nombre del evento webhook desde la clase del evento Laravel
        $eventName = Webhook::getEventName(get_class($event));

        if (! $eventName) {
            // Evento no mapeado, ignorar
            return;
        }

        // Regla de ámbito: un webhook recibe SOLO los eventos de recursos de su dueño.
        // Los eventos de otros usuarios (logins, roles, altas...) nunca se le entregan.
        $ownerId = $this->ownerIdFor($event);

        if ($ownerId === null) {
            return;
        }

        $webhooks = app(WebhookService::class)->getWebhooksForEvent($eventName, $ownerId);

        if ($webhooks->isEmpty()) {
            return;
        }

        // Preparar payload del evento
        $payload = $this->preparePayload($event, $eventName);

        // Enviar webhook a cada suscriptor
        $webhookService = app(WebhookService::class);
        foreach ($webhooks as $webhook) {
            if ($webhook->listensTo($eventName)) {
                $webhookService->send($webhook, $eventName, $payload);
            }
        }
    }

    /**
     * Dueño del recurso al que se refiere el evento: el usuario afectado.
     *
     * Es el punto donde un proyecto define su propio ámbito (p. ej. el tenant o la empresa);
     * ver docs/webhooks.md. Sin dueño identificable el evento no se entrega a nadie.
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

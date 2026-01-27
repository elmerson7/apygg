<?php

namespace App\Events\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * TestMessageBroadcast
 *
 * Evento simple para pruebas de WebSockets.
 * Permite enviar mensajes de prueba que se pueden ver en tiempo real.
 *
 * Usa ShouldBroadcastNow para ejecución inmediata sin cola.
 * NO usa SerializesModels porque no tiene modelos Eloquent.
 */
class TestMessageBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $message,
        public ?string $userId = null
    ) {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new Channel('test-messages')];

        // Si hay userId, también enviar a canal privado
        if ($this->userId) {
            $channels[] = new Channel('private-user.'.$this->userId);
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'test.message';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'timestamp' => now()->toIso8601String(),
            'time' => now()->format('H:i:s'),
        ];
    }
}

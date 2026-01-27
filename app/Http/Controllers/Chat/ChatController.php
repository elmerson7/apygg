<?php

namespace App\Http\Controllers\Chat;

use App\Events\Broadcasting\TestMessageBroadcast;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ChatController
 *
 * Controlador para el chat en tiempo real.
 * Recibe mensajes y los broadcast usando WebSockets.
 */
class ChatController extends Controller
{
    /**
     * Enviar mensaje de chat
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'username' => ['nullable', 'string', 'max:50'],
        ]);

        $message = $request->input('message');
        $username = $request->input('username', 'Usuario');

        // Formatear mensaje con username
        $formattedMessage = sprintf('%s: %s', $username, $message);

        // Broadcast el mensaje
        broadcast(new TestMessageBroadcast($formattedMessage));

        return response()->json([
            'success' => true,
            'message' => 'Mensaje enviado',
        ]);
    }
}

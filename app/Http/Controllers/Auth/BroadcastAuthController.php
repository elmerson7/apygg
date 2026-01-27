<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * BroadcastAuthController
 *
 * Controlador para autenticación de canales de broadcasting.
 * Permite a los clientes autenticarse para escuchar canales privados.
 *
 * NOTA: WebSockets es OPCIONAL. Este endpoint solo funciona si
 * BROADCAST_CONNECTION=reverb está configurado en .env
 */
class BroadcastAuthController extends Controller
{
    /**
     * Autenticar canal de broadcasting
     * Endpoint usado por Laravel Echo para autenticar canales privados
     *
     * POST /broadcasting/auth
     */
    public function authenticate(Request $request): JsonResponse
    {
        // Laravel Echo envía el channel_name y socket_id
        $channelName = $request->input('channel_name');
        $socketId = $request->input('socket_id');

        if (! $channelName || ! $socketId) {
            return response()->json([
                'success' => false,
                'message' => 'channel_name y socket_id son requeridos',
            ], 422);
        }

        // Verificar que el usuario esté autenticado
        if (! $request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado',
            ], 401);
        }

        // Usar el sistema de autenticación de broadcasting de Laravel
        // Esto maneja automáticamente la autorización según routes/channels.php
        try {
            $response = Broadcast::auth($request);

            return $response;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al autenticar canal: '.$e->getMessage(),
            ], 403);
        }
    }
}

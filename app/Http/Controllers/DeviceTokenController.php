<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DeviceTokenController
 *
 * Registro e invalidación de tokens FCM por dispositivo.
 */
class DeviceTokenController extends Controller
{
    /**
     * POST /device-tokens
     * Registra o actualiza el token FCM del dispositivo actual.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', 'in:ios,android,web'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ]);

        $user = Auth::user();

        // Upsert: si ya existe el token para este user/device, actualizar
        DeviceToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'device_id' => $request->device_id,
            ],
            [
                'token' => $request->token,
                'platform' => $request->platform,
            ]
        );

        return ApiResponse::success(null, 'Token registrado correctamente');
    }

    /**
     * DELETE /device-tokens
     * Invalida el token FCM del dispositivo (logout de notificaciones).
     */
    public function destroy(?string $id = null): JsonResponse
    {
        $request = request();
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        DeviceToken::where('user_id', Auth::id())
            ->where('token', $request->token)
            ->delete();

        return ApiResponse::success(null, 'Token eliminado correctamente');
    }
}

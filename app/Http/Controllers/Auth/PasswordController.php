<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Services\LogService;
use App\Notifications\ResetPasswordNotification;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\PasswordService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PasswordController
{
    protected PasswordService $passwordService;

    public function __construct(PasswordService $passwordService)
    {
        $this->passwordService = $passwordService;
    }

    /**
     * Solicitar reset de contraseña
     *
     * @param ForgotPasswordRequest $request
     * @return JsonResponse
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $email = $validated['email'];
            $resetUrl = $validated['reset_url'] ?? null;

            // Buscar usuario
            $user = User::where('email', $email)->first();

            if (!$user) {
                // Por seguridad, no revelar si el email existe o no
                return ApiResponse::success(
                    null,
                    'Si el email existe, recibirás un enlace para restablecer tu contraseña.'
                );
            }

            // Generar token de reset
            $token = $this->passwordService->generateResetToken($user);

            // Enviar notificación por email con URL personalizada si se proporcionó
            $user->notify(new ResetPasswordNotification($token, $resetUrl));

            LogService::info('Solicitud de reset de contraseña enviada', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'reset_url_provided' => $resetUrl !== null,
            ], 'security');

            // Por seguridad, no revelar si el email existe o no
            return ApiResponse::success(
                null,
                'Si el email existe, recibirás un enlace para restablecer tu contraseña.'
            );
        } catch (\Exception $e) {
            LogService::error('Error al solicitar reset de contraseña', [
                'email' => $request->email ?? null,
                'error' => $e->getMessage(),
            ], 'security');

            return ApiResponse::error('Error al procesar la solicitud', 500);
        }
    }

    /**
     * Resetear contraseña usando token
     *
     * @param ResetPasswordRequest $request
     * @return JsonResponse
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $email = $validated['email'];
            $token = $validated['token'];
            $password = $validated['password'];

            // Resetear contraseña usando el servicio
            $success = $this->passwordService->resetPassword($email, $token, $password);

            if (!$success) {
                LogService::warning('Intento de reset de contraseña fallido', [
                    'email' => $email,
                    'ip' => $request->ip(),
                ], 'security');

                return ApiResponse::unauthorized('Token inválido o expirado');
            }

            return ApiResponse::success(
                null,
                'Contraseña restablecida exitosamente'
            );
        } catch (\Exception $e) {
            LogService::error('Error al resetear contraseña', [
                'email' => $request->email ?? null,
                'error' => $e->getMessage(),
            ], 'security');

            return ApiResponse::error('Error al procesar el reset de contraseña', 500);
        }
    }

    /**
     * Cambiar contraseña de usuario autenticado
     *
     * @param ChangePasswordRequest $request
     * @return JsonResponse
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        try {
            $user = Auth::guard('api')->user();

            if (!$user) {
                return ApiResponse::unauthorized('Usuario no autenticado');
            }

            $validated = $request->validated();
            $currentPassword = $validated['current_password'];
            $newPassword = $validated['password'];

            // Cambiar contraseña usando el servicio
            $success = $this->passwordService->changePassword($user, $currentPassword, $newPassword);

            if (!$success) {
                return ApiResponse::unauthorized('La contraseña actual es incorrecta o la nueva contraseña es igual a la actual');
            }

            return ApiResponse::success(
                null,
                'Contraseña cambiada exitosamente'
            );
        } catch (\Exception $e) {
            LogService::error('Error al cambiar contraseña', [
                'user_id' => Auth::guard('api')->id(),
                'error' => $e->getMessage(),
            ], 'security');

            return ApiResponse::error('Error al procesar el cambio de contraseña', 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\PasswordServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Jobs\SendPasswordResetLinkJob;
use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Recuperación y cambio de contraseña. Los errores inesperados llegan al handler global.
 */
class PasswordController extends Controller
{
    public function __construct(protected PasswordServiceInterface $passwordService) {}

    /**
     * Solicitar reset de contraseña
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $email = $validated['email'];
        $resetUrl = $validated['reset_url'] ?? null;

        // Límite por email (además del de IP): no se puede inundar de
        // correos a una víctima. Se ignora en silencio para no dar pistas.
        $key = 'forgot-password:'.sha1(mb_strtolower($email));
        $limite = (int) config('auth.password_reset_per_email_per_hour', 3);

        if (RateLimiter::attempt($key, $limite, fn () => true, 3600)) {
            // Siempre se despacha, exista o no la cuenta: la respuesta y su
            // tiempo no revelan nada. El job busca al usuario.
            SendPasswordResetLinkJob::dispatch($email, $resetUrl);
        }

        LogService::info('Solicitud de reset de contraseña recibida', [
            'ip' => $request->ip(),
            'reset_url_provided' => $resetUrl !== null,
        ], 'security');

        // Por seguridad, no revelar si el email existe o no
        return $this->sendSuccess(
            null,
            'Si el email existe, recibirás un enlace para restablecer tu contraseña.'
        );
    }

    /**
     * Resetear contraseña usando token
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $email = $validated['email'];

        $success = $this->passwordService->resetPassword($email, $validated['token'], $validated['password']);

        if (! $success) {
            LogService::warning('Intento de reset de contraseña fallido', [
                'email' => $email,
                'ip' => $request->ip(),
            ], 'security');

            return $this->sendError('Token inválido o expirado', 401);
        }

        return $this->sendSuccess(null, 'Contraseña restablecida exitosamente');
    }

    /**
     * Cambiar contraseña de usuario autenticado
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        if (! $user) {
            return $this->sendError('Usuario no autenticado', 401);
        }

        $validated = $request->validated();

        $success = $this->passwordService->changePassword($user, $validated['current_password'], $validated['password']);

        if (! $success) {
            return $this->sendError('La contraseña actual es incorrecta o la nueva contraseña es igual a la actual', 401);
        }

        return $this->sendSuccess(null, 'Contraseña cambiada exitosamente');
    }
}

<?php

namespace App\Modules\Auth\Services;

use App\Infrastructure\Services\LogService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * PasswordService
 *
 * Servicio para gestión de recuperación y cambio de contraseñas:
 * - Generación de tokens de reset
 * - Validación de tokens
 * - Reset de contraseña
 * - Cambio de contraseña
 *
 * @package App\Modules\Auth\Services
 */
class PasswordService
{
    /**
     * Tiempo de expiración del token de reset en minutos
     */
    protected int $tokenExpirationMinutes = 60;

    /**
     * Generar token de reset de contraseña
     *
     * @param User $user Usuario para el cual generar el token
     * @return string Token generado
     */
    public function generateResetToken(User $user): string
    {
        // Eliminar tokens existentes para este usuario
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->delete();

        // Generar nuevo token
        $token = Str::random(64);

        // Guardar token en base de datos con expiración
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        LogService::info('Token de reset de contraseña generado', [
            'user_id' => $user->id,
            'email' => $user->email,
        ], 'security');

        return $token;
    }

    /**
     * Validar token de reset de contraseña
     *
     * @param string $email Email del usuario
     * @param string $token Token a validar
     * @return bool True si el token es válido
     */
    public function validateResetToken(string $email, string $token): bool
    {
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$record) {
            return false;
        }

        // Verificar expiración (1 hora)
        $createdAt = \Carbon\Carbon::parse($record->created_at);
        if ($createdAt->addMinutes($this->tokenExpirationMinutes)->isPast()) {
            // Token expirado, eliminarlo
            $this->deleteResetToken($email);
            return false;
        }

        // Verificar hash del token
        return Hash::check($token, $record->token);
    }

    /**
     * Obtener usuario desde token de reset
     *
     * @param string $email Email del usuario
     * @param string $token Token de reset
     * @return User|null Usuario si el token es válido
     */
    public function getUserFromResetToken(string $email, string $token): ?User
    {
        if (!$this->validateResetToken($email, $token)) {
            return null;
        }

        return User::where('email', $email)->first();
    }

    /**
     * Resetear contraseña usando token
     *
     * @param string $email Email del usuario
     * @param string $token Token de reset
     * @param string $newPassword Nueva contraseña
     * @return bool True si se reseteó exitosamente
     */
    public function resetPassword(string $email, string $token, string $newPassword): bool
    {
        $user = $this->getUserFromResetToken($email, $token);

        if (!$user) {
            LogService::warning('Intento de reset de contraseña con token inválido', [
                'email' => $email,
            ], 'security');

            return false;
        }

        // Actualizar contraseña
        $user->password = Hash::make($newPassword);
        $user->save();

        // Eliminar token usado (uso único)
        $this->deleteResetToken($email);

        LogService::info('Contraseña reseteada exitosamente', [
            'user_id' => $user->id,
            'email' => $user->email,
        ], 'security');

        return true;
    }

    /**
     * Cambiar contraseña de usuario autenticado
     *
     * @param User $user Usuario autenticado
     * @param string $currentPassword Contraseña actual
     * @param string $newPassword Nueva contraseña
     * @return bool True si se cambió exitosamente
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        // Verificar contraseña actual
        if (!Hash::check($currentPassword, $user->password)) {
            LogService::warning('Intento de cambio de contraseña con contraseña actual incorrecta', [
                'user_id' => $user->id,
            ], 'security');

            return false;
        }

        // Verificar que la nueva contraseña sea diferente
        if (Hash::check($newPassword, $user->password)) {
            LogService::warning('Intento de cambio de contraseña con la misma contraseña', [
                'user_id' => $user->id,
            ], 'security');

            return false;
        }

        // Actualizar contraseña
        $user->password = Hash::make($newPassword);
        $user->save();

        LogService::info('Contraseña cambiada exitosamente', [
            'user_id' => $user->id,
            'email' => $user->email,
        ], 'security');

        return true;
    }

    /**
     * Eliminar token de reset
     *
     * @param string $email Email del usuario
     * @return void
     */
    public function deleteResetToken(string $email): void
    {
        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->delete();
    }

    /**
     * Limpiar tokens expirados
     *
     * @return int Número de tokens eliminados
     */
    public function cleanExpiredTokens(): int
    {
        $expired = DB::table('password_reset_tokens')
            ->where('created_at', '<', now()->subMinutes($this->tokenExpirationMinutes))
            ->delete();

        LogService::info('Tokens de reset expirados eliminados', [
            'count' => $expired,
        ], 'security');

        return $expired;
    }

    /**
     * Obtener tiempo de expiración del token en minutos
     *
     * @return int Minutos de expiración
     */
    public function getTokenExpirationMinutes(): int
    {
        return $this->tokenExpirationMinutes;
    }
}

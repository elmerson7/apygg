<?php

namespace App\Contracts;

use App\Models\User;

/**
 * PasswordServiceInterface
 *
 * Contrato del servicio de restablecimiento y cambio de contraseña.
 */
interface PasswordServiceInterface
{
    /**
     * Generar token de reset de contraseña
     *
     * @param  User  $user  Usuario para el cual generar el token
     * @return string Token generado
     */
    public function generateResetToken(User $user): string;

    /**
     * Validar token de reset de contraseña
     *
     * @param  string  $email  Email del usuario
     * @param  string  $token  Token a validar
     * @return bool True si el token es válido
     */
    public function validateResetToken(string $email, string $token): bool;

    /**
     * Obtener usuario desde token de reset
     *
     * @param  string  $email  Email del usuario
     * @param  string  $token  Token de reset
     * @return User|null Usuario si el token es válido
     */
    public function getUserFromResetToken(string $email, string $token): ?User;

    /**
     * Resetear contraseña usando token
     *
     * @param  string  $email  Email del usuario
     * @param  string  $token  Token de reset
     * @param  string  $newPassword  Nueva contraseña
     * @return bool True si se reseteó exitosamente
     */
    public function resetPassword(string $email, string $token, string $newPassword): bool;

    /**
     * Cambiar contraseña de usuario autenticado
     *
     * @param  User  $user  Usuario autenticado
     * @param  string  $currentPassword  Contraseña actual
     * @param  string  $newPassword  Nueva contraseña
     * @return bool True si se cambió exitosamente
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): bool;

    /**
     * Eliminar token de reset
     *
     * @param  string  $email  Email del usuario
     */
    public function deleteResetToken(string $email): void;

    /**
     * Limpiar tokens expirados
     *
     * @return int Número de tokens eliminados
     */
    public function cleanExpiredTokens(): int;

    /**
     * Obtener tiempo de expiración del token en minutos
     *
     * @return int Minutos de expiración
     */
    public function getTokenExpirationMinutes(): int;
}

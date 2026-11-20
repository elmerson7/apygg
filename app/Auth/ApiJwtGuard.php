<?php

namespace App\Auth;

use App\Models\Blacklist;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

/**
 * Guard JWT de la API.
 *
 * Añade dos comprobaciones sobre el guard del paquete, ambas con estado solo
 * en el payload del token y en la BD (nada estático, seguro con Octane):
 *
 * - Un refresh token (`type=refresh`) no autentica peticiones: solo sirve en
 *   `POST /auth/refresh`.
 * - El claim `tv` debe coincidir con `users.token_version`; al subir la versión
 *   (cambio/reset de contraseña, bloqueo) las sesiones anteriores dejan de valer.
 * - Una identidad en la lista negra no autentica (consulta cacheada pocos segundos).
 */
class ApiJwtGuard extends JWTGuard
{
    public function user()
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $user = parent::user();

        if ($user === null) {
            return null;
        }

        $payload = $this->jwt->getPayload();

        $esRefresh = $payload->get('type') === 'refresh';
        $versionToken = (int) ($payload->get('tv') ?? 0);
        $versionUsuario = (int) ($user->token_version ?? 0);

        // Identidad bloqueada por un administrador: la sesión viva deja de valer (consulta cacheada).
        $bloqueado = $user instanceof User && Blacklist::blocksUser($user);

        if ($esRefresh || $versionToken !== $versionUsuario || $bloqueado) {
            $this->user = null;

            return null;
        }

        return $user;
    }
}

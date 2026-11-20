<?php

namespace App\Contracts;

use App\Exceptions\AuditException;
use App\Exceptions\ImpersonationException;
use App\Models\User;

interface ImpersonationServiceInterface
{
    /**
     * Emite un access token de corta duración para actuar como `$target` (sin refresh token).
     *
     * @return array{access_token: string, token_type: string, expires_in: int, impersonating: array{user_id: int, impersonator_id: int, tenant_id: int|null}}
     *
     * @throws ImpersonationException
     * @throws AuditException sin motivo
     */
    public function start(User $actor, User $target, ?string $reason, ?int $tenantId = null): array;

    /**
     * Termina la impersonación en curso: revoca el token actual y lo audita.
     *
     * @throws ImpersonationException si la sesión no es una impersonación
     */
    public function stop(User $impersonated): void;
}

<?php

namespace App\Contracts;

use App\Models\User;
use App\Models\UserSession;

/**
 * Sesiones de usuario (una por login) y su refresh token rotado. docs/sessions-and-2fa.md.
 */
interface SessionServiceInterface
{
    /**
     * Abre una sesión nueva con su primer `refresh_jti`.
     */
    public function start(User $user, ?string $ip, ?string $userAgent): UserSession;

    /**
     * Identificador nuevo e impredecible para un refresh token.
     */
    public function newRefreshJti(): string;

    /**
     * Sesiones activas del usuario, la más reciente primero.
     *
     * @return list<array{id: string, device: ?string, ip: ?string, created_at: string, last_used_at: ?string, current: bool}>
     */
    public function listFor(User $user, ?string $currentSid): array;

    /**
     * Revoca una sesión PROPIA (404 si no existe, es de otro usuario o ya estaba revocada).
     */
    public function revoke(User $user, string $publicId, string $reason = 'user'): void;

    /**
     * Revoca todas las sesiones activas del usuario menos la indicada. Devuelve cuántas.
     */
    public function revokeOthers(User $user, ?string $exceptSid, string $reason = 'user'): int;

    /**
     * Revoca una sesión por su id público (logout). Idempotente.
     */
    public function revokeBySid(string $sid, string $reason = 'logout'): void;

    /**
     * ¿Sigue activa? (consulta barata y cacheada pocos segundos; la revocación la invalida).
     */
    public function isActive(string $sid): bool;

    /**
     * Anota el último uso (como máximo una escritura por intervalo).
     */
    public function touch(string $sid): void;

    public const ROTATED = 'rotated';

    public const SUPERSEDED = 'superseded';

    public const REJECTED = 'rejected';

    /**
     * Rota el refresh token de forma atómica: solo gana quien presenta el `jti` vigente.
     *
     * - `ROTATED`: esta llamada ganó.
     * - `SUPERSEDED`: presentó el `jti` inmediatamente anterior dentro de la gracia
     *   (`auth.sessions.refresh_reuse_grace_seconds`): carrera legítima, la sesión sigue viva.
     * - `REJECTED`: sesión inexistente o revocada, o reutilización real (jti anterior fuera de la gracia o
     *   más antiguo): en ese caso la sesión se revoca (posible robo).
     *
     * @return self::ROTATED|self::SUPERSEDED|self::REJECTED
     */
    public function rotate(string $sid, int $userId, string $presentedJti, string $newJti): string;
}

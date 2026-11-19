<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Utilidades de los equipos (teams) de Spatie para multiempresa. Modelo (docs/permissions.md):
 *
 * - Equipo = tenant. Las ASIGNACIONES de roles y permisos directos son por equipo.
 * - Equipo 0 (`PermissionTeamsSchema::PLATFORM_TEAM`) = plataforma: lo que tiene un usuario fuera de
 *   cualquier tenant (admin global, soporte con `tenants.access-any`).
 * - `roles.tenant_id` NULL = rol global (catálogo común); con valor = rol propio de ese tenant.
 */
final class PermissionTeams
{
    public static function enabled(): bool
    {
        return (bool) config('permission.teams');
    }

    /**
     * Ejecuta el callback evaluando los roles y permisos del usuario en el equipo de la plataforma
     * y restaura el equipo anterior (y las relaciones cacheadas del usuario) al terminar.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function inPlatform(User $user, Closure $callback): mixed
    {
        return self::inTeam($user, PermissionTeamsSchema::PLATFORM_TEAM, $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function inTeam(User $user, int $teamId, Closure $callback): mixed
    {
        if (! self::enabled()) {
            return $callback();
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($teamId);
        self::forgetUserRelations($user);

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            self::forgetUserRelations($user);
        }
    }

    /**
     * Spatie cachea `roles` y `permissions` en el modelo: al cambiar de equipo hay que soltarlos.
     */
    public static function forgetUserRelations(User $user): void
    {
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }
}

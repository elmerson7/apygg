<?php

namespace App\Support\Tenancy;

use App\Enums\TenantMembershipStatus;
use App\Exceptions\Tenancy\TenantOnboardingException;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LogService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles DEL TENANT (`tenant_user.role`), configurados en `multi-tenancy.onboarding.roles`.
 *
 * `tenant_user.role` es la fuente de verdad (jerarquía y salvaguardas); el rol de Spatie homónimo
 * (`tenant-owner`...) solo da los permisos de ruta (`tenant-members.*`). Con `PERMISSION_TEAMS=true` se asigna
 * y se retira en el equipo del tenant. Con equipos apagados los roles de Spatie son GLOBALES: solo se
 * AÑADEN (retirarlos afectaría a otros tenants del mismo usuario) y la jerarquía la sigue imponiendo
 * `tenant_user.role` en el servicio. Ver docs/tenant-onboarding.md.
 */
final class TenantRoles
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map('strval', array_keys((array) config('multi-tenancy.onboarding.roles', [])));
    }

    public static function exists(string $role): bool
    {
        return in_array($role, self::names(), true);
    }

    public static function rank(string $role): int
    {
        return (int) config("multi-tenancy.onboarding.roles.{$role}.rank", 0);
    }

    public static function maxRank(): int
    {
        return (int) max(array_map(self::rank(...), self::names()) ?: [0]);
    }

    public static function owner(): string
    {
        $names = self::names();
        usort($names, fn (string $a, string $b) => self::rank($b) <=> self::rank($a));

        return $names[0] ?? 'owner';
    }

    /**
     * Rango del actor en ESTE tenant, exigiendo que su rol del tenant conceda `$permission`.
     *
     * El `permission:` de la ruta no basta: con equipos de Spatie apagados los roles `tenant-*` son
     * globales, así que el rol de admin en otra empresa pasaría la ruta aquí. La capacidad sale del rol
     * de `tenant_user` en este tenant (vía su rol de Spatie). Sin membresía activa solo se llega con
     * `tenants.access-any` (soporte de plataforma): rango máximo.
     */
    public static function actorRankFor(Tenant $tenant, User $actor, string $permission): int
    {
        $role = DB::table('tenant_user')
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', TenantMembershipStatus::Active->value)
            ->value('role');

        if (! is_string($role)) {
            return self::maxRank();
        }

        if (! self::allows($role, $permission)) {
            throw TenantOnboardingException::roleInsufficient();
        }

        return self::rank($role);
    }

    /**
     * ¿El rol del tenant concede ese permiso? (por los permisos de su rol de Spatie).
     */
    public static function allows(string $role, string $permission): bool
    {
        $spatie = self::spatieRole($role);

        return $spatie !== null && $spatie->permissions->contains('name', $permission);
    }

    public static function default(): string
    {
        return (string) config('multi-tenancy.onboarding.default_role', 'member');
    }

    /**
     * Refleja el rol del tenant en Spatie para ese usuario y tenant (ver la nota de la clase).
     */
    public static function sync(User $user, Tenant $tenant, string $role): void
    {
        $context = app(TenantContext::class);

        $context->run($tenant, function () use ($user, $role): void {
            PermissionTeams::forgetUserRelations($user);

            $target = self::spatieRole($role);

            if (PermissionTeams::enabled()) {
                foreach (self::names() as $other) {
                    $name = self::spatieName($other);
                    if ($other !== $role && $name !== null && $user->hasRole($name)) {
                        $user->removeRole($name);
                    }
                }
            }

            if ($target !== null && ! $user->hasRole($target->name)) {
                $user->assignRole($target);
            }

            PermissionTeams::forgetUserRelations($user);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Retira los roles de tenant de Spatie del usuario en ese tenant (solo con equipos activos).
     */
    public static function revoke(User $user, Tenant $tenant): void
    {
        if (! PermissionTeams::enabled()) {
            return;
        }

        app(TenantContext::class)->run($tenant, function () use ($user): void {
            PermissionTeams::forgetUserRelations($user);

            foreach (self::names() as $role) {
                $name = self::spatieName($role);
                if ($name !== null && $user->hasRole($name)) {
                    $user->removeRole($name);
                }
            }

            PermissionTeams::forgetUserRelations($user);
        });
    }

    private static function spatieName(string $role): ?string
    {
        $name = config("multi-tenancy.onboarding.roles.{$role}.spatie_role");

        return is_string($name) && $name !== '' ? $name : null;
    }

    private static function spatieRole(string $role): ?Role
    {
        $name = self::spatieName($role);

        if ($name === null) {
            return null;
        }

        $found = Role::query()->where('name', $name)->where('guard_name', 'api')->when(
            PermissionTeams::enabled(),
            fn ($q) => $q->whereNull(config('permission.column_names.team_foreign_key', 'tenant_id')),
        )->first();

        if (! $found instanceof Role) {
            LogService::warning("tenancy: falta el rol de Spatie '{$name}' (ejecuta RoleSeeder); el rol del tenant se guardó igualmente", [], 'security');
        }

        return $found;
    }
}

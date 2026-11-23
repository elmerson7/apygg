<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsTypedInput;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fija el rol de tenant (`tenant_user.role`) de un miembro existente. Sirve para nombrar al primer dueño en
 * instalaciones que ya tenían membresías (la migración las deja como `member`). Tarea de plataforma.
 */
class TenancySetRoleCommand extends Command
{
    use ReadsTypedInput;

    protected $signature = 'tenancy:set-role {tenant : Tenant (id o slug)} {email : Email del miembro} {role : owner|admin|member...}';

    protected $description = 'Fija el rol de tenant de un miembro (útil para nombrar al primer dueño)';

    public function handle(): int
    {
        $identifier = $this->stringArgument('tenant') ?? '';
        $tenant = ctype_digit($identifier) ? Tenant::query()->find((int) $identifier) : Tenant::query()->bySlug($identifier)->first();
        $role = $this->stringArgument('role') ?? '';

        if (! $tenant instanceof Tenant) {
            $this->error("Tenant '{$identifier}' no encontrado.");

            return self::FAILURE;
        }

        if (! TenantRoles::exists($role)) {
            $this->error('Rol no válido. Opciones: '.implode(', ', TenantRoles::names()));

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(($this->stringArgument('email') ?? ''))])->first();
        $updated = $user instanceof User
            ? DB::table('tenant_user')->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->update(['role' => $role, 'updated_at' => now()])
            : 0;

        if (! $user instanceof User || $updated === 0) {
            $this->error('Esa persona no es miembro del tenant.');

            return self::FAILURE;
        }

        TenantRoles::sync($user, $tenant, $role);
        $this->info("{$user->email} ahora es '{$role}' en '{$tenant->slug}'.");

        return self::SUCCESS;
    }
}

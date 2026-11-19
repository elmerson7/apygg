<?php

namespace App\Providers;

use App\Helpers\DevToolsAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Settings;
use App\Models\User;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\SettingsPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * AuthServiceProvider
 *
 * Service provider para registrar policies y configuración de autorización.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        Permission::class => PermissionPolicy::class,
        Settings::class => SettingsPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Super admin: el rol admin pasa cualquier comprobación de permiso (can(), middleware
        // `permission:`...) aunque el permiso no esté asignado o ni siquiera exista.
        //
        // Es Gate::after y no Gate::before (la variante habitual de la doc de Spatie) A PROPÓSITO:
        // after solo actúa cuando nadie ha decidido todavía (resultado null). Así una policy que
        // deniega de forma explícita sigue mandando también sobre el admin: no borrarse a sí
        // mismo (UserPolicy::delete), no borrar el rol admin (RolePolicy::delete)... Con before
        // esas salvaguardas se saltarían. Ver docs/permissions.md.
        Gate::after(fn ($user, $ability, $result) => $user instanceof User && $user->isAdmin() ? true : null);

        // Telescope: abierto solo en 'local'; en otro entorno, admin o email autorizado
        Gate::define('viewTelescope', fn ($user = null) => DevToolsAccess::allows($user));
    }
}

<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * PermissionSeeder
 *
 * Crea exactamente los permisos que usan las rutas (`permission:`), las policies,
 * el menú (MenuSeeder) y los controllers. Nombre: `recurso.accion`.
 *
 * Un test (tests/Feature/Authorization/PermissionCatalogTest.php) compara esta
 * lista con lo que usa el código: si añades un permiso a una ruta o policy, añádelo
 * aquí, y viceversa. Los proyectos derivados añaden aquí los suyos.
 *
 * Idempotente: firstOrCreate por (name, guard_name); no pisa display_name ni
 * description editados después. Solo crea permisos; quién los tiene lo decide RoleSeeder.
 */
class PermissionSeeder extends Seeder
{
    /**
     * @return array<int, array{name: string, display_name: string, description: string}>
     */
    public static function catalog(): array
    {
        return [
            // Usuarios
            ['name' => 'users.read', 'display_name' => 'Ver Usuarios', 'description' => 'Permite ver el listado y el detalle de usuarios'],
            ['name' => 'users.create', 'display_name' => 'Crear Usuarios', 'description' => 'Permite crear nuevos usuarios'],
            ['name' => 'users.update', 'display_name' => 'Actualizar Usuarios', 'description' => 'Permite editar los datos de otros usuarios'],
            ['name' => 'users.delete', 'display_name' => 'Eliminar Usuarios', 'description' => 'Permite eliminar usuarios'],
            ['name' => 'users.restore', 'display_name' => 'Restaurar Usuarios', 'description' => 'Permite restaurar usuarios eliminados'],
            ['name' => 'users.manage-roles', 'display_name' => 'Gestionar Roles de Usuarios', 'description' => 'Permite asignar y quitar roles a usuarios'],

            // Roles
            ['name' => 'roles.read', 'display_name' => 'Ver Roles', 'description' => 'Permite ver el listado y el detalle de roles'],
            ['name' => 'roles.create', 'display_name' => 'Crear Roles', 'description' => 'Permite crear nuevos roles'],
            ['name' => 'roles.update', 'display_name' => 'Actualizar Roles', 'description' => 'Permite editar los datos de un rol'],
            ['name' => 'roles.delete', 'display_name' => 'Eliminar Roles', 'description' => 'Permite eliminar roles'],
            ['name' => 'roles.manage-permissions', 'display_name' => 'Gestionar Permisos de Roles', 'description' => 'Permite asignar y quitar permisos a roles'],

            // Permisos
            ['name' => 'permissions.read', 'display_name' => 'Ver Permisos', 'description' => 'Permite ver el listado y el detalle de permisos'],
            ['name' => 'permissions.create', 'display_name' => 'Crear Permisos', 'description' => 'Permite crear nuevos permisos'],
            ['name' => 'permissions.update', 'display_name' => 'Actualizar Permisos', 'description' => 'Permite editar permisos'],
            ['name' => 'permissions.delete', 'display_name' => 'Eliminar Permisos', 'description' => 'Permite eliminar permisos'],

            // Archivos
            ['name' => 'files.manage', 'display_name' => 'Gestionar Archivos de Otros', 'description' => 'Permite ver, descargar, editar y borrar los archivos de otros usuarios (los propios no lo necesitan)'],

            // Correo
            ['name' => 'mail.manage', 'display_name' => 'Gestionar Correo', 'description' => 'Permite administrar proveedores, cuentas, plantillas y ajustes de correo'],
            ['name' => 'mail.logs', 'display_name' => 'Ver Historial de Correo', 'description' => 'Permite consultar el historial y las estadísticas de correos enviados'],

            // Seguridad
            ['name' => 'security.audit', 'display_name' => 'Auditoría de Seguridad', 'description' => 'Permite lanzar y consultar escaneos de seguridad'],
            ['name' => 'security.blacklist', 'display_name' => 'Gestionar Lista de Bloqueo', 'description' => 'Permite bloquear y desbloquear identidades (correo, documento, usuario)'],

            // Menú
            ['name' => 'menu.manage', 'display_name' => 'Gestionar Menú', 'description' => 'Permite configurar los ítems del menú y su visibilidad'],

            // IA
            ['name' => 'llm.manage', 'display_name' => 'Gestionar IA', 'description' => 'Permite configurar las áreas y modelos de IA'],
            ['name' => 'llm.logs', 'display_name' => 'Ver Auditoría de IA', 'description' => 'Permite consultar el consumo y la auditoría de las llamadas a IA'],

            // Multiempresa (docs/multi-tenancy.md)
            ['name' => 'tenants.access-any', 'display_name' => 'Acceder a Cualquier Tenant', 'description' => 'Permite entrar en cualquier tenant activo sin ser miembro (soporte de plataforma)'],

            ['name' => 'tenant-settings.manage', 'display_name' => 'Gestionar Ajustes del Tenant', 'description' => 'Permite cambiar los ajustes de la empresa activa (idioma, zona horaria y moneda por defecto)'],
            ['name' => 'tenant-members.read', 'display_name' => 'Ver Miembros del Tenant', 'description' => 'Permite listar los miembros de la empresa activa'],
            ['name' => 'tenant-members.manage', 'display_name' => 'Gestionar Miembros del Tenant', 'description' => 'Permite cambiar el rol, suspender, reactivar y quitar miembros de la empresa activa'],
            ['name' => 'tenant-invitations.manage', 'display_name' => 'Gestionar Invitaciones del Tenant', 'description' => 'Permite listar, crear, reenviar y revocar invitaciones a la empresa activa'],

            // Back-office (Filament, opcional): ver docs/backoffice.md
            ['name' => 'backoffice.access', 'display_name' => 'Acceder al Back-office', 'description' => 'Permite entrar al panel interno (Filament); además exige MFA. Cada recurso pide sus propios permisos'],

            // Sistema
            ['name' => 'system.settings', 'display_name' => 'Gestionar Configuración', 'description' => 'Permite ver la sección de configuración del sistema en el menú'],
            ['name' => 'system.logs', 'display_name' => 'Ver Logs', 'description' => 'Permite ver los logs de actividad, API y seguridad'],
            ['name' => 'system.logs-delete', 'display_name' => 'Borrar Logs', 'description' => 'Permite borrar los logs de actividad, API y seguridad'],
        ];
    }

    public function run(): void
    {
        foreach (self::catalog() as $permission) {
            [$resource, $action] = explode('.', $permission['name'], 2);

            Permission::firstOrCreate(
                ['name' => $permission['name'], 'guard_name' => 'api'],
                [
                    'display_name' => $permission['display_name'],
                    'description' => $permission['description'],
                    'resource' => $resource,
                    'action' => $action,
                ]
            );
        }

        // Los seeders corren sin eventos de modelo: hay que vaciar la caché de Spatie a mano.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command->info('Permisos base: '.count(self::catalog()));
    }
}

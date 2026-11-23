<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

/**
 * MenuSeeder
 *
 * Ítems de menú correspondientes a los módulos que trae el boilerplate.
 * Cada uno se ata a un permiso, de modo que la visibilidad queda derivada de
 * la autorización real y no hay que mantener dos listas.
 *
 * Es idempotente.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['key' => 'dashboard', 'label' => 'Panel', 'route' => '/dashboard', 'icon' => 'home', 'permission' => null, 'sort_order' => 0],

            ['key' => 'usuarios', 'label' => 'Usuarios', 'route' => '/users', 'icon' => 'users', 'permission' => 'users.read', 'sort_order' => 10],
            ['key' => 'roles', 'label' => 'Roles', 'route' => '/roles', 'icon' => 'shield', 'permission' => 'roles.read', 'sort_order' => 20],
            ['key' => 'permisos', 'label' => 'Permisos', 'route' => '/permissions', 'icon' => 'key', 'permission' => 'permissions.read', 'sort_order' => 30],

            ['key' => 'configuracion', 'label' => 'Configuración', 'route' => null, 'icon' => 'settings', 'permission' => 'system.settings', 'sort_order' => 80],
            ['key' => 'configuracion.correo', 'label' => 'Correo', 'route' => '/admin/mail', 'icon' => 'mail', 'permission' => 'mail.manage', 'parent_key' => 'configuracion', 'sort_order' => 81],
            ['key' => 'configuracion.ia', 'label' => 'IA', 'route' => '/admin/llm', 'icon' => 'sparkles', 'permission' => 'llm.manage', 'parent_key' => 'configuracion', 'sort_order' => 82], // module:llm
            ['key' => 'configuracion.menu', 'label' => 'Menú', 'route' => '/admin/menu', 'icon' => 'list', 'permission' => 'menu.manage', 'parent_key' => 'configuracion', 'sort_order' => 83],

            ['key' => 'auditoria', 'label' => 'Auditoría', 'route' => null, 'icon' => 'file-text', 'permission' => 'system.logs', 'sort_order' => 90],
            ['key' => 'auditoria.logs', 'label' => 'Logs del sistema', 'route' => '/admin/logs', 'icon' => 'file-text', 'permission' => 'system.logs', 'parent_key' => 'auditoria', 'sort_order' => 91],
            ['key' => 'auditoria.correo', 'label' => 'Historial de correo', 'route' => '/admin/mail/logs', 'icon' => 'send', 'permission' => 'mail.logs', 'parent_key' => 'auditoria', 'sort_order' => 92],
            ['key' => 'auditoria.ia', 'label' => 'Consumo de IA', 'route' => '/admin/llm/logs', 'icon' => 'activity', 'permission' => 'llm.logs', 'parent_key' => 'auditoria', 'sort_order' => 93], // module:llm
        ];

        foreach ($items as $item) {
            MenuItem::updateOrCreate(
                ['key' => $item['key']],
                array_merge(['is_active' => true, 'parent_key' => null], $item)
            );
        }

        $this->command?->info('Ítems de menú registrados: '.MenuItem::count());
        $this->command?->warn("El ítem 'dashboard' no tiene permiso: asígnale roles desde /admin/menu o no lo verá nadie.");
    }
}

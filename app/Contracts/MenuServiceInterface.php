<?php

namespace App\Contracts;

use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * MenuServiceInterface
 *
 * Contrato del menú dinámico por rol.
 */
interface MenuServiceInterface
{
    /**
     * Claves de los ítems visibles para un usuario.
     *
     * @return array<int, string>
     */
    public function getUserMenuKeys(User $user): array;

    /**
     * Ítems visibles para un usuario, en orden.
     *
     * @return Collection<int, MenuItem>
     */
    public function getVisibleItems(User $user): Collection;

    /**
     * Menú visible en forma de árbol, listo para renderizar.
     *
     * Un ítem hijo cuyo padre no es visible se descarta: mostrarlo suelto
     * produciría un menú incoherente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUserMenuTree(User $user): array;

    /**
     * Catálogo completo con sus roles, para la pantalla de configuración.
     *
     * @return Collection<int, MenuItem>
     */
    public function getCatalog(): Collection;

    /**
     * Reemplazar las asignaciones de roles de los ítems indicados.
     * Los ítems que no aparezcan en `$assignments` no se tocan.
     *
     * @param  array<int, array{key: string, role_ids?: array<int, int>}>  $assignments
     */
    public function syncRoleAssignments(array $assignments): void;

    /**
     * Roles disponibles para asignar en la pantalla de configuración.
     *
     * @return Collection<int, Role>
     */
    public function availableRoles(): Collection;
}

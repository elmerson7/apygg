<?php

namespace App\Services\Menu;

use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * MenuService
 *
 * Resuelve qué ítems del menú ve cada usuario.
 *
 * El backend devuelve solo las claves visibles y el cliente renderiza; así la
 * decisión de visibilidad vive en un único sitio y no hay que replicar la
 * lógica de permisos en el front.
 *
 * Importante: esto es **presentación, no autorización**. Ocultar un ítem no
 * protege el endpoint; cada ruta debe seguir exigiendo su permiso.
 */
class MenuService
{
    /**
     * Claves de los ítems visibles para un usuario.
     *
     * @return array<int, string>
     */
    public function getUserMenuKeys(User $user): array
    {
        return $this->getVisibleItems($user)->pluck('key')->values()->all();
    }

    /**
     * Ítems visibles para un usuario, en orden.
     *
     * @return Collection<int, MenuItem>
     */
    public function getVisibleItems(User $user): Collection
    {
        $roleIds = $user->roles()->pluck('roles.id');

        return MenuItem::query()
            ->active()
            ->with('roles:id')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (MenuItem $item) => $this->isVisibleTo($item, $user, $roleIds->all()))
            ->values();
    }

    /**
     * Menú visible en forma de árbol, listo para renderizar.
     *
     * Un ítem hijo cuyo padre no es visible se descarta: mostrarlo suelto
     * produciría un menú incoherente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUserMenuTree(User $user): array
    {
        $visible = $this->getVisibleItems($user);

        return $visible
            ->filter(fn (MenuItem $item) => $item->parent_key === null)
            ->map(fn (MenuItem $item) => $this->toNode($item, $visible))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, MenuItem>  $visible
     * @return array<string, mixed>
     */
    protected function toNode(MenuItem $item, Collection $visible): array
    {
        $children = $visible
            ->filter(fn (MenuItem $child) => $child->parent_key === $item->key)
            ->map(fn (MenuItem $child) => $this->toNode($child, $visible))
            ->values()
            ->all();

        return [
            'key' => $item->key,
            'label' => $item->label,
            'route' => $item->route,
            'icon' => $item->icon,
            'children' => $children,
        ];
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    protected function isVisibleTo(MenuItem $item, User $user, array $roleIds): bool
    {
        if ($item->permission) {
            // Un permiso inexistente simplemente no se concede (Spatie no lanza vía can()).
            return $user->can($item->permission);
        }

        if ($roleIds === []) {
            return false;
        }

        return $item->roles->pluck('id')->intersect($roleIds)->isNotEmpty();
    }

    /**
     * Catálogo completo con sus roles, para la pantalla de configuración.
     *
     * @return Collection<int, MenuItem>
     */
    public function getCatalog(): Collection
    {
        return MenuItem::with('roles:id,name')->orderBy('sort_order')->get();
    }

    /**
     * Reemplazar las asignaciones de roles de los ítems indicados.
     * Los ítems que no aparezcan en `$assignments` no se tocan.
     *
     * @param  array<int, array{key: string, role_ids?: array<int, int>}>  $assignments
     */
    public function syncRoleAssignments(array $assignments): void
    {
        foreach ($assignments as $assignment) {
            MenuItem::where('key', $assignment['key'])
                ->first()
                ?->roles()
                ->sync($assignment['role_ids'] ?? []);
        }
    }

    /**
     * Roles disponibles para asignar en la pantalla de configuración.
     *
     * @return Collection<int, Role>
     */
    public function availableRoles(): Collection
    {
        return Role::query()->orderBy('id')->get(['id', 'name', 'display_name']);
    }
}

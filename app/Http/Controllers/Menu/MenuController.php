<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Menu\StoreMenuItemRequest;
use App\Http\Requests\Menu\UpdateMenuItemRequest;
use App\Http\Requests\Menu\UpdateMenuVisibilityRequest;
use App\Http\Resources\Menu\MenuItemResource;
use App\Models\MenuItem;
use App\Services\Menu\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MenuController
 *
 * Menú del usuario autenticado y configuración del catálogo.
 *
 * Recuerda: el menú es presentación, no autorización. Ocultar un ítem no
 * protege nada; cada ruta sigue exigiendo su permiso.
 */
class MenuController extends ApiController
{
    public function __construct(private readonly MenuService $menu) {}

    /**
     * Menú del usuario autenticado, en árbol.
     */
    public function mine(Request $request): JsonResponse
    {
        return $this->sendSuccess([
            'items' => $this->menu->getUserMenuTree($request->user()),
            'keys' => $this->menu->getUserMenuKeys($request->user()),
        ], 'Menú obtenido exitosamente');
    }

    /**
     * Catálogo completo con roles asignados y roles disponibles.
     */
    public function catalog(): JsonResponse
    {
        return $this->sendSuccess([
            'items' => MenuItemResource::collection($this->menu->getCatalog()),
            'roles' => $this->menu->availableRoles(),
        ], 'Catálogo de menú obtenido exitosamente');
    }

    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        $item = MenuItem::create($request->validated());

        if ($roleIds = $request->input('role_ids')) {
            $item->roles()->sync($roleIds);
        }

        return $this->sendSuccess(
            new MenuItemResource($item->load('roles:id,name')),
            'Ítem de menú creado exitosamente',
            201
        );
    }

    public function update(UpdateMenuItemRequest $request, MenuItem $menuItem): JsonResponse
    {
        $menuItem->update($request->safe()->except('role_ids'));

        if ($request->has('role_ids')) {
            $menuItem->roles()->sync($request->input('role_ids', []));
        }

        return $this->sendSuccess(
            new MenuItemResource($menuItem->load('roles:id,name')),
            'Ítem de menú actualizado exitosamente'
        );
    }

    public function destroy(MenuItem $menuItem): JsonResponse
    {
        if ($menuItem->children()->exists()) {
            return $this->sendError('No se puede eliminar un ítem que tiene hijos', 422);
        }

        $menuItem->delete();

        return $this->sendSuccess(null, 'Ítem de menú eliminado exitosamente');
    }

    /**
     * Actualizar la visibilidad por rol de varios ítems a la vez.
     *
     * Body: { "items": [{ "key": "usuarios", "role_ids": [1, 2] }] }
     */
    public function updateVisibility(UpdateMenuVisibilityRequest $request): JsonResponse
    {
        $this->menu->syncRoleAssignments($request->validated('items'));

        return $this->sendSuccess(null, 'Visibilidad del menú actualizada exitosamente');
    }
}

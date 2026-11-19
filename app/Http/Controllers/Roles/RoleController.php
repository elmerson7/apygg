<?php

namespace App\Http\Controllers\Roles;

use App\Contracts\RoleServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\SyncRolePermissionsRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class RoleController extends Controller
{
    public function __construct(
        protected RoleServiceInterface $roleService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $roles = $this->roleService->list($request->only(['search', 'per_page']));

        return $this->sendPaginated($roles);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $role = $this->roleService->find($id);

        $this->authorize('view', $role);

        return $this->sendSuccess($role);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Asignar permisos al crear exige roles.manage-permissions, igual que PUT /roles/{id}/permissions
        $permissionIds = $validated['permission_ids'] ?? null;
        if ($permissionIds !== null) {
            $this->authorize('assignPermission', new Role);
            $this->roleService->assertCanGrantPermissions(auth('api')->user(), $permissionIds);
        }

        $role = $this->roleService->create($validated);

        if ($permissionIds !== null) {
            $this->roleService->syncPermissions((string) $role->id, $permissionIds);
        }

        return $this->sendSuccess($role->fresh('permissions'), 'Rol creado exitosamente', 201);
    }

    public function update(UpdateRoleRequest $request, string $id): JsonResponse
    {
        $role = $this->roleService->find($id);
        $validated = $request->validated();

        // Cambiar permisos exige roles.manage-permissions, no basta roles.update
        $permissionIds = $validated['permission_ids'] ?? null;
        if ($permissionIds !== null) {
            $this->authorize('assignPermission', $role);
            $this->roleService->assertCanGrantPermissions(auth('api')->user(), $permissionIds);
        }

        $this->roleService->update($id, Arr::except($validated, ['permission_ids']));

        if ($permissionIds !== null) {
            $this->roleService->syncPermissions($id, $permissionIds);
        }

        return $this->sendSuccess($this->roleService->find($id), 'Rol actualizado exitosamente');
    }

    public function destroy(string $id): JsonResponse
    {
        $role = $this->roleService->find($id);

        $this->authorize('delete', $role);

        $this->roleService->delete($id);

        return $this->sendSuccess(null, 'Rol eliminado exitosamente');
    }

    public function permissions(string $id): JsonResponse
    {
        $role = $this->roleService->find($id);

        $this->authorize('view', $role);

        return $this->sendSuccess($this->roleService->getPermissions($id));
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, string $id): JsonResponse
    {
        $this->roleService->find($id);

        $validated = $request->validated();

        $this->roleService->assertCanGrantPermissions(auth('api')->user(), $validated['permissions']);

        $this->roleService->syncPermissions($id, $validated['permissions']);

        return $this->sendSuccess($this->roleService->getPermissions($id), 'Permisos actualizados exitosamente');
    }
}

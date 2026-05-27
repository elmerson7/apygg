<?php

namespace App\Http\Controllers\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(
        protected RoleService $roleService
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

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $formRequest = new StoreRoleRequest;
        $validated = $request->validate($formRequest->rules(), $formRequest->messages());

        $role = $this->roleService->create($validated);

        if ($request->has('permission_ids')) {
            $this->roleService->syncPermissions((string) $role->id, $request->input('permission_ids'));
        }

        return $this->sendSuccess($role->fresh('permissions'), 'Rol creado exitosamente', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $role = $this->roleService->find($id);

        $this->authorize('update', $role);

        $formRequest = (new UpdateRoleRequest)->setRoleId($id);
        $validated = $request->validate($formRequest->rules(), $formRequest->messages());

        $this->roleService->update($id, $validated);

        if ($request->has('permission_ids')) {
            $this->roleService->syncPermissions($id, $request->input('permission_ids'));
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

    public function syncPermissions(Request $request, string $id): JsonResponse
    {
        $role = $this->roleService->find($id);

        $this->authorize('assignPermission', $role);

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $this->roleService->syncPermissions($id, $validated['permissions']);

        return $this->sendSuccess($this->roleService->getPermissions($id), 'Permisos actualizados exitosamente');
    }
}

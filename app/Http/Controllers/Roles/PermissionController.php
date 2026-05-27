<?php

namespace App\Http\Controllers\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permissions\StorePermissionRequest;
use App\Http\Requests\Permissions\UpdatePermissionRequest;
use App\Models\Permission;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        $permissions = $this->permissionService->list($request->only(['search', 'resource', 'action', 'per_page']));

        return $this->sendPaginated($permissions);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $permission = $this->permissionService->find($id);

        $this->authorize('view', $permission);

        return $this->sendSuccess($permission);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Permission::class);

        $formRequest = new StorePermissionRequest;
        $validated = $request->validate($formRequest->rules(), $formRequest->messages());

        $permission = $this->permissionService->create($validated);

        return $this->sendSuccess($permission->fresh(), 'Permiso creado exitosamente', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $permission = $this->permissionService->find($id);

        $this->authorize('update', $permission);

        $formRequest = new UpdatePermissionRequest;
        $validated = $request->validate($formRequest->rules(), $formRequest->messages());

        $this->permissionService->update($id, array_intersect_key($validated, array_flip(['name', 'display_name', 'description'])));

        return $this->sendSuccess($this->permissionService->find($id), 'Permiso actualizado exitosamente');
    }

    public function destroy(string $id): JsonResponse
    {
        $permission = $this->permissionService->find($id);

        $this->authorize('delete', $permission);

        $this->permissionService->delete($id);

        return $this->sendSuccess(null, 'Permiso eliminado exitosamente');
    }

    public function grouped(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        $all = $this->permissionService->all();
        $grouped = $all->groupBy('resource')->map(fn ($perms) => $perms->values());

        return $this->sendSuccess($grouped);
    }
}

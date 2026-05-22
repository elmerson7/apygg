<?php

namespace App\Http\Controllers\Roles;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = Role::with('permissions')
            ->when($request->search, fn ($q) => $q->search($request->search))
            ->orderBy($request->sort ?? 'created_at', $request->order ?? 'desc')
            ->paginate($request->per_page ?? 20);

        return $this->sendSuccess($roles);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        return $this->sendSuccess($role);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:roles,name',
            'display_name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
        ]);

        $role = Role::create($validated);

        return $this->sendSuccess($role, 'Rol creado', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:50|unique:roles,name,'.$id,
            'display_name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:255',
        ]);

        $role->update($validated);

        return $this->sendSuccess($role->fresh('permissions'), 'Rol actualizado');
    }

    public function destroy(string $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if ($role->users()->count() > 0) {
            return $this->sendError('No se puede eliminar el rol porque tiene usuarios asignados', 422);
        }

        $role->delete();

        return $this->sendSuccess(null, 'Rol eliminado');
    }

    public function permissions(string $id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        return $this->sendSuccess($role->permissions);
    }

    public function syncPermissions(Request $request, string $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role->permissions()->sync($validated['permissions']);

        return $this->sendSuccess($role->fresh('permissions'), 'Permisos actualizados');
    }
}

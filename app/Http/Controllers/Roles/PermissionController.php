<?php

namespace App\Http\Controllers\Roles;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $permissions = Permission::when($request->search, fn ($q) => $q->search($request->search))
            ->orderBy($request->sort ?? 'created_at', $request->order ?? 'desc')
            ->paginate($request->per_page ?? 20);

        return $this->sendSuccess($permissions);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);

        return $this->sendSuccess($permission);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:permissions,name',
            'display_name' => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
        ]);

        $permission = Permission::create($validated);

        return $this->sendSuccess($permission, 'Permiso creado', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100|unique:permissions,name,'.$id,
            'display_name' => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:255',
        ]);

        $permission->update($validated);

        return $this->sendSuccess($permission, 'Permiso actualizado');
    }

    public function destroy(string $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);

        if ($permission->roles()->count() > 0) {
            return $this->sendError('No se puede eliminar el permiso porque está asignado a roles', 422);
        }

        $permission->delete();

        return $this->sendSuccess(null, 'Permiso eliminado');
    }
}

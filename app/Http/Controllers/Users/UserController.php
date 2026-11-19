<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AssignRoleRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\Users\UserDetailResource;
use App\Http\Resources\Users\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UserController
 *
 * Controlador para gestión de usuarios.
 * La lógica de negocio vive en UserService.
 */
class UserController extends Controller
{
    /**
     * Resource para transformar respuestas
     */
    protected ?string $resource = UserResource::class;

    /**
     * Relaciones que el cliente puede pedir con `?include=`
     */
    protected array $allowedRelations = ['roles', 'permissions', 'activityLogs', 'apiTokens'];

    public function __construct(protected UserService $userService) {}

    /**
     * Display a listing of users.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $filters = [
            'search' => $request->get('search'),
            'role' => $request->get('role'),
            'email' => $request->get('email'),
            'per_page' => $request->get('per_page', 15),
            'sort' => $request->get('sort', 'created_at'),
            'order' => $request->get('order', 'desc'),
        ];

        $users = $this->userService->list($filters);

        return $this->sendPaginated($users);
    }

    /**
     * Display the specified user.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->userService->find($id);

        $this->authorize('view', $user);

        // Cargar relaciones si se solicitan
        $include = $request->get('include', '');
        if ($include) {
            $relations = explode(',', $include);
            $allowedRelations = array_intersect($relations, $this->allowedRelations);
            if (! empty($allowedRelations)) {
                $user->load($allowedRelations);
            }
        }

        return $this->sendSuccess(new UserDetailResource($user), 'Usuario obtenido exitosamente');
    }

    /**
     * Store a newly created user.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $roleIds = $validated['role_ids'] ?? [];
        unset($validated['role_ids']);

        $user = $this->userService->create($validated, $roleIds);

        return $this->sendSuccess($user, 'Usuario creado exitosamente', 201);
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        // `UpdateUserRequest::authorize()` ya comprobó la policy; los campos permitidos
        // dependen de quién edita (ver sus reglas).
        $user = $this->userService->update($id, $request->validated());

        return $this->sendSuccess($user, 'Usuario actualizado exitosamente');
    }

    /**
     * Remove the specified user (soft delete).
     */
    public function destroy(string $id): JsonResponse
    {
        $user = $this->userService->find($id);

        $this->authorize('delete', $user);

        $this->userService->delete($id);

        return $this->sendSuccess(null, 'Usuario eliminado exitosamente');
    }

    /**
     * Restore a soft-deleted user.
     */
    public function restore(string $id): JsonResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);

        $this->authorize('restore', $user);

        $user = $this->userService->restore($id);

        return $this->sendSuccess($user, 'Usuario restaurado exitosamente');
    }

    /**
     * Assign roles to a user.
     */
    public function assignRoles(AssignRoleRequest $request, string $id): JsonResponse
    {
        // `AssignRoleRequest::authorize()`: users.manage-roles + jerarquía
        // (no tocar admins ni dar más privilegios que los propios)
        $user = $this->userService->find($id);
        $validated = $request->validated();

        $roleIds = array_map('intval', $validated['role_ids']);

        // Cada rol que se añade o se quita debe poder ser asignado por quien actúa
        $currentIds = $user->roles->pluck('id')->map(fn ($v) => (int) $v)->all();
        $changed = array_merge(array_diff($roleIds, $currentIds), array_diff($currentIds, $roleIds));
        foreach (Role::whereIn('id', $changed)->get() as $role) {
            $this->authorize('assign', $role);
        }

        $user = $this->userService->assignRoles($id, $roleIds);

        return $this->sendSuccess($user, 'Roles asignados exitosamente');
    }

    /**
     * Remove a role from a user.
     */
    public function removeRole(string $id, string $roleId): JsonResponse
    {
        $user = $this->userService->find($id);

        $this->authorize('manageRoles', $user);
        $this->authorize('assign', Role::findOrFail($roleId));

        $user = $this->userService->removeRole($id, $roleId);

        return $this->sendSuccess($user, 'Rol removido exitosamente');
    }

    /**
     * Get activity logs for a user.
     */
    public function getActivity(Request $request, string $id): JsonResponse
    {
        $user = $this->userService->find($id);

        // Solo puede ver su propia actividad o si tiene permiso users.read
        $this->authorize('view', $user);

        $perPage = $request->get('per_page', 15);
        $activityLogs = $this->userService->getActivityLogs($id, $perPage);

        return $this->sendPaginated($activityLogs, resource: false);
    }
}

<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AssignRoleRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\Users\UserDetailResource;
use App\Http\Resources\Users\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UserController
 *
 * Controlador para gestión de usuarios.
 * Extiende Controller base y usa UserService para lógica de negocio.
 */
class UserController extends Controller
{
    protected UserService $userService;

    /**
     * Modelo asociado al controlador
     */
    protected ?string $model = User::class;

    /**
     * Resource para transformar respuestas
     */
    protected ?string $resource = UserResource::class;

    /**
     * Relaciones permitidas para eager loading
     */
    protected array $allowedRelations = ['roles', 'permissions', 'activityLogs', 'apiTokens'];

    /**
     * Campos permitidos para ordenamiento
     */
    protected array $allowedSortFields = ['name', 'email', 'created_at', 'updated_at'];

    /**
     * Campos permitidos para filtrado
     */
    protected array $allowedFilterFields = ['email'];

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

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

        // Usar UserDetailResource para vista detallada
        $resource = new UserDetailResource($user);

        return response()->json([
            'success' => true,
            'message' => 'Usuario obtenido exitosamente',
            'data' => $resource,
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        // Validar usando las reglas de StoreUserRequest
        $formRequest = new StoreUserRequest;
        $validated = $request->validate($formRequest->rules(), $formRequest->messages());

        $roleIds = $request->input('role_ids', []);

        $user = $this->userService->create($validated, $roleIds);

        return $this->sendSuccess($user, 'Usuario creado exitosamente', 201);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->userService->find($id);

        $this->authorize('update', $user);

        // Validar usando las reglas de UpdateUserRequest
        // Construir reglas manualmente con el $id correcto
        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                \Illuminate\Validation\Rule::unique('users', 'email')->ignore($id),
            ],
            'password' => ['sometimes', 'string', 'min:8', new \App\Rules\StrongPassword],
        ];

        $messages = [
            'name.string' => 'El nombre debe ser texto',
            'name.max' => 'El nombre no puede exceder 255 caracteres',
            'email.email' => 'El email debe ser válido',
            'email.unique' => 'El email ya está en uso',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
        ];

        $validated = $request->validate($rules, $messages);
        $user = $this->userService->update($id, $validated);

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
    public function assignRoles(Request $request, string $id): JsonResponse
    {
        $user = $this->userService->find($id);

        // Verificar permiso usando policy
        $this->authorize('update', $user);

        // Validar usando las reglas de AssignRoleRequest
        $formRequest = new AssignRoleRequest;
        $validated = $request->validate($formRequest->rules(), $formRequest->messages());

        $roleIds = $validated['role_ids'];
        $user = $this->userService->assignRoles($id, $roleIds);

        return $this->sendSuccess($user, 'Roles asignados exitosamente');
    }

    /**
     * Remove a role from a user.
     */
    public function removeRole(string $id, string $roleId): JsonResponse
    {
        $user = $this->userService->find($id);

        // Verificar permiso usando policy
        $this->authorize('update', $user);

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

        return response()->json([
            'success' => true,
            'data' => $activityLogs->items(),
            'meta' => [
                'current_page' => $activityLogs->currentPage(),
                'per_page' => $activityLogs->perPage(),
                'total' => $activityLogs->total(),
                'last_page' => $activityLogs->lastPage(),
            ],
        ]);
    }
}

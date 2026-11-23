<?php

namespace App\Http\Controllers\Users;

use App\Contracts\AuditServiceInterface;
use App\Contracts\UserServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AssignRoleRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\Users\UserDetailResource;
use App\Http\Resources\Users\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\Listing\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UserController
 *
 * Controlador para gestión de usuarios.
 * La lógica de negocio vive en UserServiceInterface.
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
    /** @var list<string> */
    protected array $allowedRelations = ['roles', 'permissions', 'activityLogs', 'apiTokens'];

    public function __construct(protected UserServiceInterface $userService, protected AuditServiceInterface $audit) {}

    /**
     * Display a listing of users.
     *
     * `filter[search|role|exclude_roles|name|email|username|identity_document]`, `sort=-created_at,name`, `include=permissions`.
     * `?search=`, `?role=`, `?email=`, `?sort=` + `?order=` siguen funcionando como alias.
     */
    #[Listing(
        filters: [
            'search' => 'custom', 'role' => 'custom', 'exclude_roles' => 'custom',
            'name' => 'partial', 'email' => 'partial', 'username' => 'partial', 'identity_document' => 'partial',
        ],
        sorts: ['name', 'email', 'username', 'identity_document', 'created_at', 'updated_at', 'email_verified_at'],
        includes: ['roles', 'profile', 'permissions'],
        legacy: ['search', 'role', 'email'],
        defaultPerPage: 15,
        maxPerPage: 500,
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $listing = $this->listingQuery($request);

        $users = $this->userService->list([
            'search' => $listing->filter('search'),
            'role' => $listing->filter('role'),
            'exclude_roles' => $listing->filter('exclude_roles'),
            'per_page' => $request->get('per_page', 15),
            'page' => $request->get('page', 1),
            'listing' => $listing,
        ]);

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
        $roleIds = array_values(array_map('intval', $validated['role_ids'] ?? []));
        unset($validated['role_ids']);

        // Mismo control que al asignar roles: nadie crea un usuario con un rol
        // que no podría asignarle (p. ej. un manager no crea admins).
        foreach (Role::whereIn('id', $roleIds)->get() as $role) {
            $this->authorize('assign', $role);
        }

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

        $roleIds = array_values(array_map('intval', $validated['role_ids']));

        // Cada rol que se añade o se quita debe poder ser asignado por quien actúa
        $currentIds = $user->roles->pluck('id')->map(fn ($v) => (int) $v)->all();
        $changed = array_merge(array_diff($roleIds, $currentIds), array_diff($currentIds, $roleIds));
        foreach (Role::whereIn('id', $changed)->get() as $role) {
            $this->authorize('assign', $role);
        }

        $reason = $this->audit->requireReason('user.roles-change', $validated['reason'] ?? null);

        $user = $this->userService->assignRoles($id, $roleIds);

        $this->audit->recordAction('user.roles-change', $user, $reason, ['role_ids_before' => $currentIds, 'role_ids_after' => $roleIds]);

        return $this->sendSuccess($user, 'Roles asignados exitosamente');
    }

    /**
     * Remove a role from a user.
     */
    public function removeRole(Request $request, string $id, string $roleId): JsonResponse
    {
        $user = $this->userService->find($id);

        $this->authorize('manageRoles', $user);
        $this->authorize('assign', Role::findOrFail($roleId));

        $reason = $this->audit->requireReason('user.roles-change', $request->input('reason'));

        $user = $this->userService->removeRole($id, $roleId);

        $this->audit->recordAction('user.roles-change', $user, $reason, ['role_id_removed' => (int) $roleId]);

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

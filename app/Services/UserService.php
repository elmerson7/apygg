<?php

namespace App\Services;

use App\Contracts\RoleRepositoryInterface;
use App\Contracts\UserRepositoryInterface;
use App\Events\PermissionGranted;
use App\Events\PermissionRevoked;
use App\Events\RoleAssigned;
use App\Events\RoleRemoved;
use App\Events\UserCreated;
use App\Events\UserDeleted;
use App\Events\UserRestored;
use App\Events\UserUpdated;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

/**
 * UserService
 *
 * Servicio para gestión de usuarios:
 * - CRUD completo de usuarios
 * - Gestión de roles y permisos
 * - Búsqueda con filtros avanzados
 */
class UserService
{
    protected const CACHE_TTL = 3600;

    protected const CACHE_PREFIX = 'user:';

    /**
     * @var UserRepositoryInterface
     */
    protected $userRepository;

    /**
     * @var RoleRepositoryInterface
     */
    protected $roleRepository;

    /**
     * Constructor
     */
    public function __construct(
        UserRepositoryInterface $userRepository,
        RoleRepositoryInterface $roleRepository
    ) {
        $this->userRepository = $userRepository;
        $this->roleRepository = $roleRepository;
    }

    /**
     * Crear un nuevo usuario
     *
     * @throws \InvalidArgumentException Si el email ya existe
     */
    public function create(array $data, ?array $roleIds = null): User
    {
        if ($this->userRepository->findByEmail($data['email'])) {
            throw new \InvalidArgumentException("El email '{$data['email']}' ya está en uso");
        }

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        // Generar name desde first_name y last_name, y separarlos para profile
        $profileData = [];
        if (isset($data['first_name'])) {
            $profileData['first_name'] = $data['first_name'];
            unset($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $profileData['last_name'] = $data['last_name'];
            unset($data['last_name']);
        }

        if (isset($profileData['first_name']) && isset($profileData['last_name'])) {
            $data['name'] = trim($profileData['first_name'].' '.$profileData['last_name']);
        } elseif (isset($profileData['first_name'])) {
            $data['name'] = $profileData['first_name'];
        }

        if (! isset($data['state_id'])) {
            $data['state_id'] = 1;
        }

        $user = $this->userRepository->create($data);

        // Crear profile con first_name y last_name
        if (! empty($profileData)) {
            $user->profile()->create($profileData);
        }

        if (! empty($roleIds)) {
            $user->roles()->sync($roleIds);
        }

        $this->clearCache();
        event(new UserCreated($user));

        return $user->fresh(['roles', 'permissions', 'profile']);
    }

    /**
     * Actualizar un usuario existente.
     *
     * @throws ModelNotFoundException
     * @throws \InvalidArgumentException Si el email ya existe
     */
    public function update(string $userId, array $data, ?array $roleIds = null): User
    {
        $user = $this->find($userId);

        if (isset($data['email']) && $data['email'] !== $user->email) {
            if ($this->userRepository->findByEmail($data['email']) && $this->userRepository->findByEmail($data['email'])->id != $userId) {
                throw new \InvalidArgumentException("El email '{$data['email']}' ya está en uso");
            }
        }

        $oldAttributes = $user->getAttributes();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        unset($data['role_ids']);

        // Generar name desde first_name y last_name si se enviaron
        $profileData = [];
        if (isset($data['first_name'])) {
            $profileData['first_name'] = $data['first_name'];
            unset($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $profileData['last_name'] = $data['last_name'];
            unset($data['last_name']);
        }

        if (isset($profileData['first_name']) && isset($profileData['last_name'])) {
            $data['name'] = trim($profileData['first_name'].' '.$profileData['last_name']);
        } elseif (isset($profileData['first_name'])) {
            $data['name'] = $profileData['first_name'];
        }

        $user->update($data);

        // Actualizar profile si se enviaron first_name o last_name
        if (! empty($profileData)) {
            if ($user->profile) {
                $user->profile->update($profileData);
            } else {
                $user->profile()->create($profileData);
            }
        }

        if ($roleIds !== null) {
            $this->syncUserRoles($user, $roleIds);
        }

        $this->clearCache($userId);
        event(new UserUpdated($user, $oldAttributes));

        return $user->fresh(['roles', 'permissions', 'profile']);
    }

    /**
     * Sync roles for a user, firing appropriate events for added/removed roles.
     */
    protected function syncUserRoles(User $user, array $roleIds): void
    {
        $existingRoles = Role::whereIn('id', $roleIds)->pluck('id')->toArray();
        $invalidRoles = array_diff($roleIds, $existingRoles);
        if (! empty($invalidRoles)) {
            throw new \InvalidArgumentException('Los siguientes roles no existen: '.implode(', ', $invalidRoles));
        }

        $previousRoleIds = $user->roles()->select('roles.id')->pluck('id')->toArray();
        $user->roles()->sync($roleIds);

        foreach (array_diff($roleIds, $previousRoleIds) as $roleId) {
            if ($role = Role::find($roleId)) {
                event(new RoleAssigned($user, $role));
            }
        }
        foreach (array_diff($previousRoleIds, $roleIds) as $roleId) {
            if ($role = Role::find($roleId)) {
                event(new RoleRemoved($user, $role));
            }
        }
    }

    /**
     * Actualizar preferencias del usuario (JSON en users.preferences).
     * Normaliza: sms, push, email en raíz se mueven a notifications.
     *
     * @throws ModelNotFoundException
     */
    public function updatePreferences(string $userId, array $preferences): User
    {
        $user = $this->find($userId);
        $normalized = $this->normalizePreferencesPayload($preferences);
        $existing = is_array($user->preferences) ? $user->preferences : [];
        $merged = array_replace_recursive($existing, $normalized);
        $user->update(['preferences' => $merged]);
        $this->clearCache($userId);

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @return array<string, mixed>
     */
    protected function normalizePreferencesPayload(array $preferences): array
    {
        $notificationKeys = ['sms', 'push', 'email'];
        $atRoot = array_intersect_key($preferences, array_flip($notificationKeys));
        if ($atRoot === []) {
            return $preferences;
        }
        $out = $preferences;
        foreach ($notificationKeys as $key) {
            unset($out[$key]);
        }
        $out['notifications'] = array_replace_recursive($out['notifications'] ?? [], $atRoot);

        return $out;
    }

    /**
     * Eliminar un usuario (soft delete)
     *
     * @throws ModelNotFoundException
     */
    public function delete(string $userId): bool
    {
        $user = $this->find($userId);
        $deleted = $this->userRepository->delete($userId);
        $this->clearCache($userId);
        event(new UserDeleted($user));

        return $deleted;
    }

    /**
     * Restaurar un usuario eliminado
     *
     * @throws ModelNotFoundException
     */
    public function restore(string $userId): User
    {
        $user = User::onlyTrashed()->findOrFail($userId);
        $user->restore();
        $this->clearCache($userId);
        event(new UserRestored($user));

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Buscar un usuario por ID
     *
     * @throws ModelNotFoundException
     */
    public function find(string $userId): User
    {
        return CacheService::remember(self::CACHE_PREFIX.$userId, self::CACHE_TTL, function () use ($userId) {
            $user = $this->userRepository->find($userId);
            $user->load(['roles', 'permissions']);

            return $user;
        });
    }

    /**
     * Listar usuarios con paginación y filtros.
     * Carga roles, state por defecto; permissions solo si se solicita en include.
     *
     * @param  array  $filters  ['search', 'per_page', 'page', 'include', 'role', 'exclude_roles']
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max(1, (int) ($filters['per_page'] ?? 20)), 500);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $search = $filters['search'] ?? null;
        $include = $filters['include'] ?? '';
        $role = $filters['role'] ?? null;
        $excludeRoles = $filters['exclude_roles'] ?? null;
        $sort = $filters['sort'] ?? 'created_at';
        $order = strtolower($filters['order'] ?? 'desc');
        $order = in_array($order, ['asc', 'desc']) ? $order : 'desc';
        $sortableColumns = ['name', 'email', 'username', 'identity_document', 'created_at', 'updated_at', 'email_verified_at'];
        if (! in_array($sort, $sortableColumns)) {
            $sort = 'created_at';
        }

        $relations = ['roles', 'profile'];
        if ($include !== '') {
            $requested = array_map('trim', explode(',', $include));
            if (in_array('permissions', $requested, true)) {
                $relations[] = 'permissions';
            }
        }

        $query = User::with($relations);

        if ($role && trim((string) $role) !== '') {
            $query->whereHas('roles', fn ($q) => $q->where('roles.name', trim($role))->where('roles.guard_name', 'api'));
        }

        if ($excludeRoles && trim((string) $excludeRoles) !== '') {
            $rolesToExclude = array_map('trim', explode(',', $excludeRoles));
            $query->whereDoesntHave('roles', fn ($q) => $q->whereIn('roles.name', $rolesToExclude)->where('roles.guard_name', 'api'));
        }

        // Híbrido: Meilisearch (typo tolerance para nombre/email) + SQL ILIKE (substring para identity_document)
        if ($search && trim($search) !== '') {
            $searchTerm = trim($search);
            $searchPattern = '%'.$searchTerm.'%';

            $matchedIds = collect();
            $meilisearchAvailable = config('scout.driver') === 'meilisearch' && $this->meilisearchIsAvailable();

            if ($meilisearchAvailable) {
                // 1. Meilisearch: typo tolerance para name, email, username
                $matchedIds = User::search($searchTerm)->get()->pluck('id');

                // 2. SQL ILIKE: substring matching para identity_document
                //    (Meilisearch no soporta %substring%)
                $docIds = User::where('identity_document', 'ILIKE', $searchPattern)->pluck('id');

                $allIds = $matchedIds->merge($docIds)->unique()->values();

                if ($allIds->isNotEmpty()) {
                    $query->whereIn('id', $allIds);
                } else {
                    $query->whereRaw('1 = 0');
                }
            } else {
                // Fallback: SQL ILIKE en todos los campos si Meilisearch no está disponible
                $query->where(fn ($q) => $q
                    ->where('name', 'ILIKE', $searchPattern)
                    ->orWhere('username', 'ILIKE', $searchPattern)
                    ->orWhere('email', 'ILIKE', $searchPattern)
                    ->orWhere('identity_document', 'ILIKE', $searchPattern)
                    ->orWhereHas('roles', fn ($r) => $r->where('name', 'ILIKE', $searchPattern))
                );
            }
        }

        return $query->orderBy($sort, $order)->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Asignar roles a un usuario
     *
     * @throws ModelNotFoundException
     * @throws \InvalidArgumentException Si algún rol no existe
     */
    public function assignRoles(string $userId, array $roleIds): User
    {
        $user = $this->find($userId);

        $existingRoles = Role::whereIn('id', $roleIds)->pluck('id')->toArray();
        $invalidRoles = array_diff($roleIds, $existingRoles);
        if (! empty($invalidRoles)) {
            throw new \InvalidArgumentException('Los siguientes roles no existen: '.implode(', ', $invalidRoles));
        }

        $previousRoleIds = $user->roles()->select('roles.id')->pluck('id')->toArray();
        $user->roles()->sync($roleIds);
        $this->clearCache($userId);

        foreach (array_diff($roleIds, $previousRoleIds) as $roleId) {
            if ($role = Role::find($roleId)) {
                event(new RoleAssigned($user, $role));
            }
        }
        foreach (array_diff($previousRoleIds, $roleIds) as $roleId) {
            if ($role = Role::find($roleId)) {
                event(new RoleRemoved($user, $role));
            }
        }

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Remover un rol de un usuario
     *
     * @throws ModelNotFoundException
     */
    public function removeRole(string $userId, string $roleId): User
    {
        $user = $this->find($userId);
        $role = Role::findOrFail($roleId);

        if ($user->roles()->count() === 1 && $user->hasRole('admin')) {
            throw new \Exception('No se puede remover el último rol de un administrador');
        }

        $user->roles()->detach($roleId);
        $this->clearCache($userId);
        event(new RoleRemoved($user, $role));

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Asignar permisos directos a un usuario
     *
     * @throws ModelNotFoundException
     */
    public function assignPermissions(string $userId, array $permissionIds): User
    {
        $user = $this->find($userId);
        $previousPermissionIds = $user->permissions()->select('permissions.id')->pluck('id')->toArray();
        $user->permissions()->sync($permissionIds);
        $this->clearCache($userId);

        foreach (array_diff($permissionIds, $previousPermissionIds) as $permissionId) {
            if ($permission = Permission::find($permissionId)) {
                event(new PermissionGranted($user, $permission));
            }
        }
        foreach (array_diff($previousPermissionIds, $permissionIds) as $permissionId) {
            if ($permission = Permission::find($permissionId)) {
                event(new PermissionRevoked($user, $permission));
            }
        }

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Remover un permiso directo de un usuario
     *
     * @throws ModelNotFoundException
     */
    public function removePermission(string $userId, string $permissionId): User
    {
        $user = $this->find($userId);
        $permission = Permission::findOrFail($permissionId);

        $user->permissions()->detach($permissionId);
        $this->clearCache($userId);
        event(new PermissionRevoked($user, $permission));

        LogService::info('Permiso removido de usuario', [
            'user_id' => $userId,
            'permission_id' => $permissionId,
        ], 'activity');

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Obtener historial de actividad de un usuario
     *
     * @throws ModelNotFoundException
     */
    public function getActivityLogs(string $userId, int $perPage = 20): LengthAwarePaginator
    {
        $user = $this->find($userId);

        return $user->activityLogs()->orderBy('created_at', 'desc')->paginate($perPage);
    }

    private function meilisearchIsAvailable(): bool
    {
        try {
            $host = config('scout.meilisearch.host');
            $ch = curl_init($host.'/health');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $httpCode === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function clearCache(?string $userId = null): void
    {
        if ($userId) {
            CacheService::forget(self::CACHE_PREFIX.$userId);
        }
        CacheService::forget(self::CACHE_PREFIX.'list');
    }
}

<?php

namespace App\Services;

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
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * Crear un nuevo usuario
     *
     * @throws \InvalidArgumentException Si el email ya existe
     */
    public function create(array $data, ?array $roleIds = null): User
    {
        if (User::where('email', $data['email'])->exists()) {
            throw new \InvalidArgumentException("El email '{$data['email']}' ya está en uso");
        }

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        if (! isset($data['state_id'])) {
            $data['state_id'] = 1;
        }

        $user = User::create($data);

        if (! empty($roleIds)) {
            $user->roles()->sync($roleIds);
        }

        $this->clearCache();
        event(new UserCreated($user));

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Actualizar un usuario existente.
     *
     * @throws ModelNotFoundException
     * @throws \InvalidArgumentException Si el email ya existe
     */
    public function update(string $userId, array $data): User
    {
        $user = $this->find($userId);

        if (isset($data['email']) && $data['email'] !== $user->email) {
            if (User::where('email', $data['email'])->where('id', '!=', $userId)->exists()) {
                throw new \InvalidArgumentException("El email '{$data['email']}' ya está en uso");
            }
        }

        $oldAttributes = $user->getAttributes();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);
        $this->clearCache($userId);
        event(new UserUpdated($user, $oldAttributes));

        return $user->fresh(['roles', 'permissions']);
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
        $deleted = $user->delete();
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
            return User::with(['roles', 'permissions'])->findOrFail($userId);
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

        $relations = ['roles', 'state'];
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

        if ($search && trim($search) !== '') {
            $searchPattern = '%'.trim($search).'%';
            $query->where(fn ($q) => $q
                ->where('first_name', 'ILIKE', $searchPattern)
                ->orWhere('last_name', 'ILIKE', $searchPattern)
                ->orWhere('email', 'ILIKE', $searchPattern)
                ->orWhere('identity_document', 'ILIKE', $searchPattern)
            );
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
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

    protected function clearCache(?string $userId = null): void
    {
        if ($userId) {
            CacheService::forget(self::CACHE_PREFIX.$userId);
        }
        CacheService::forget(self::CACHE_PREFIX.'list');
    }
}

<?php

namespace App\Services;

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
 * - Notificaciones de bienvenida
 */
class UserService
{
    /**
     * Cache TTL para usuarios (en segundos)
     */
    protected const CACHE_TTL = 3600; // 1 hora

    /**
     * Cache key prefix
     */
    protected const CACHE_PREFIX = 'user:';

    /**
     * Crear un nuevo usuario
     *
     * @param  array  $data  Datos del usuario
     * @param  array|null  $roleIds  IDs de roles a asignar (opcional)
     *
     * @throws \InvalidArgumentException Si el email ya existe
     */
    public function create(array $data, ?array $roleIds = null): User
    {
        // Validar que el email sea único
        if (User::where('email', $data['email'])->exists()) {
            throw new \InvalidArgumentException("El email '{$data['email']}' ya está en uso");
        }

        // Hash de contraseña si se proporciona
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        // Crear usuario
        $user = User::create($data);

        // Asignar roles si se proporcionan
        if (! empty($roleIds)) {
            $user->roles()->sync($roleIds);
        } else {
            // Asignar rol 'user' por defecto si no se especifica
            $defaultRole = Role::where('name', 'user')->first();
            if ($defaultRole) {
                $user->roles()->attach($defaultRole->id);
            }
        }

        // Limpiar cache
        $this->clearCache();

        // Disparar evento UserCreated
        event(new \App\Events\UserCreated($user));

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Actualizar un usuario existente
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $data  Datos a actualizar
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el usuario no existe
     * @throws \InvalidArgumentException Si el email ya existe
     */
    public function update(string $userId, array $data): User
    {
        $user = $this->find($userId);

        // Validar que el email sea único (si se está cambiando)
        if (isset($data['email']) && $data['email'] !== $user->email) {
            if (User::where('email', $data['email'])->where('id', '!=', $userId)->exists()) {
                throw new \InvalidArgumentException("El email '{$data['email']}' ya está en uso");
            }
        }

        // Guardar valores anteriores para el evento
        $oldAttributes = $user->getAttributes();

        // Hash de contraseña si se proporciona
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        // Limpiar cache
        $this->clearCache($userId);

        // Disparar evento UserUpdated
        event(new \App\Events\UserUpdated($user, $oldAttributes));

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Eliminar un usuario (soft delete)
     *
     * @param  string  $userId  ID del usuario
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el usuario no existe
     */
    public function delete(string $userId): bool
    {
        $user = $this->find($userId);
        $deleted = $user->delete();

        // Limpiar cache
        $this->clearCache($userId);

        // Disparar evento UserDeleted
        event(new \App\Events\UserDeleted($user));

        return $deleted;
    }

    /**
     * Restaurar un usuario eliminado
     *
     * @param  string  $userId  ID del usuario
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el usuario no existe
     */
    public function restore(string $userId): User
    {
        $user = User::onlyTrashed()->findOrFail($userId);
        $user->restore();

        // Limpiar cache
        $this->clearCache($userId);

        // Disparar evento UserRestored
        event(new \App\Events\UserRestored($user));

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Buscar un usuario por ID
     *
     * @param  string  $userId  ID del usuario
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el usuario no existe
     */
    public function find(string $userId): User
    {
        $cacheKey = self::CACHE_PREFIX.$userId;

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
            return User::with(['roles', 'permissions'])->findOrFail($userId);
        });
    }

    /**
     * Listar usuarios con paginación y filtros
     *
     * @param  array  $filters  Filtros ['search', 'role', 'email', 'per_page']
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $perPage = $filters['per_page'] ?? 15;
        $search = $filters['search'] ?? null;
        $role = $filters['role'] ?? null;
        $email = $filters['email'] ?? null;

        $query = User::with(['roles', 'permissions']);

        // Aplicar búsqueda si existe (usar ILIKE para PostgreSQL case-insensitive)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('email', 'ILIKE', "%{$search}%");
            });
        }

        // Filtrar por email
        if ($email) {
            $query->byEmail($email);
        }

        // Filtrar por rol
        if ($role) {
            $query->byRole($role);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Asignar roles a un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $roleIds  IDs de roles a asignar
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el usuario no existe
     */
    public function assignRoles(string $userId, array $roleIds): User
    {
        $user = $this->find($userId);

        // Validar que los roles existan
        $existingRoles = Role::whereIn('id', $roleIds)->pluck('id')->toArray();
        $invalidRoles = array_diff($roleIds, $existingRoles);

        if (! empty($invalidRoles)) {
            throw new \InvalidArgumentException('Los siguientes roles no existen: '.implode(', ', $invalidRoles));
        }

        // Obtener roles anteriores para comparar
        $previousRoleIds = $user->roles()->select('roles.id')->pluck('id')->toArray();

        // Sincronizar roles (reemplaza todos los roles existentes)
        $user->roles()->sync($roleIds);

        // Limpiar cache
        $this->clearCache($userId);

        // Disparar eventos para roles nuevos y removidos
        $newRoleIds = array_diff($roleIds, $previousRoleIds);
        $removedRoleIds = array_diff($previousRoleIds, $roleIds);

        foreach ($newRoleIds as $roleId) {
            $role = Role::find($roleId);
            if ($role) {
                event(new \App\Events\RoleAssigned($user, $role));
            }
        }

        foreach ($removedRoleIds as $roleId) {
            $role = Role::find($roleId);
            if ($role) {
                event(new \App\Events\RoleRemoved($user, $role));
            }
        }

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Remover un rol de un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  string  $roleId  ID del rol a remover
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el usuario o rol no existe
     */
    public function removeRole(string $userId, string $roleId): User
    {
        $user = $this->find($userId);
        $role = Role::findOrFail($roleId);

        // No permitir remover el último rol si es admin
        if ($user->roles()->count() === 1 && $user->hasRole('admin')) {
            throw new \Exception('No se puede remover el último rol de un administrador');
        }

        $user->roles()->detach($roleId);

        // Limpiar cache
        $this->clearCache($userId);

        // Disparar evento RoleRemoved
        event(new \App\Events\RoleRemoved($user, $role));

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Asignar permisos directos a un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $permissionIds  IDs de permisos a asignar
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el usuario no existe
     */
    public function assignPermissions(string $userId, array $permissionIds): User
    {
        $user = $this->find($userId);

        // Obtener permisos anteriores para comparar
        $previousPermissionIds = $user->permissions()->select('permissions.id')->pluck('id')->toArray();

        $user->permissions()->sync($permissionIds);

        // Limpiar cache
        $this->clearCache($userId);

        // Disparar eventos para permisos nuevos y removidos
        $newPermissionIds = array_diff($permissionIds, $previousPermissionIds);
        $removedPermissionIds = array_diff($previousPermissionIds, $permissionIds);

        foreach ($newPermissionIds as $permissionId) {
            $permission = Permission::find($permissionId);
            if ($permission) {
                event(new \App\Events\PermissionGranted($user, $permission));
            }
        }

        foreach ($removedPermissionIds as $permissionId) {
            $permission = Permission::find($permissionId);
            if ($permission) {
                event(new \App\Events\PermissionRevoked($user, $permission));
            }
        }

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Remover un permiso directo de un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  string  $permissionId  ID del permiso a remover
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el usuario no existe
     */
    public function removePermission(string $userId, string $permissionId): User
    {
        $user = $this->find($userId);
        $permission = Permission::findOrFail($permissionId);

        $user->permissions()->detach($permissionId);

        // Limpiar cache
        $this->clearCache($userId);

        // Disparar evento PermissionRevoked
        event(new \App\Events\PermissionRevoked($user, $permission));

        // Log de auditoría
        LogService::info('Permiso removido de usuario', [
            'user_id' => $userId,
            'permission_id' => $permissionId,
        ], 'activity');

        return $user->fresh(['roles', 'permissions']);
    }

    /**
     * Obtener historial de actividad de un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  int  $perPage  Número de resultados por página
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el usuario no existe
     */
    public function getActivityLogs(string $userId, int $perPage = 15): LengthAwarePaginator
    {
        $user = $this->find($userId);

        return $user->activityLogs()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Limpiar cache de usuarios
     *
     * @param  string|null  $userId  ID específico del usuario o null para limpiar todo
     */
    protected function clearCache(?string $userId = null): void
    {
        if ($userId) {
            CacheService::forget(self::CACHE_PREFIX.$userId);
        }

        // Limpiar cache de lista completa
        CacheService::forget(self::CACHE_PREFIX.'list');
    }
}

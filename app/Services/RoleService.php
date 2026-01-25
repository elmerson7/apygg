<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * RoleService
 *
 * Servicio para gestión de roles del sistema RBAC:
 * - CRUD completo de roles
 * - Asignación y remoción de permisos
 * - Validaciones y cache
 */
class RoleService
{
    /**
     * Cache TTL para roles (en segundos)
     */
    protected const CACHE_TTL = 3600; // 1 hora

    /**
     * Cache key prefix
     */
    protected const CACHE_PREFIX = 'role:';

    /**
     * Crear un nuevo rol
     *
     * @param  array  $data  Datos del rol ['name', 'display_name', 'description']
     *
     * @throws \Illuminate\Database\QueryException Si el nombre ya existe
     */
    public function create(array $data): Role
    {
        // Validar que el nombre sea único
        if (Role::where('name', $data['name'])->exists()) {
            throw new \InvalidArgumentException("El rol '{$data['name']}' ya existe");
        }

        $role = Role::create([
            'name' => $data['name'],
            'display_name' => $data['display_name'] ?? $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        // Limpiar cache
        $this->clearCache();

        // Log de auditoría
        LogService::info('Rol creado', [
            'role_id' => $role->id,
            'role_name' => $role->name,
        ], 'activity');

        return $role;
    }

    /**
     * Actualizar un rol existente
     *
     * @param  string  $roleId  ID del rol
     * @param  array  $data  Datos a actualizar
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el rol no existe
     */
    public function update(string $roleId, array $data): Role
    {
        $role = $this->find($roleId);

        // Validar que el nombre sea único (si se está cambiando)
        if (isset($data['name']) && $data['name'] !== $role->name) {
            if (Role::where('name', $data['name'])->where('id', '!=', $roleId)->exists()) {
                throw new \InvalidArgumentException("El rol '{$data['name']}' ya existe");
            }
        }

        $role->update($data);

        // Limpiar cache
        $this->clearCache($roleId);

        // Log de auditoría
        LogService::info('Rol actualizado', [
            'role_id' => $role->id,
            'role_name' => $role->name,
            'changes' => $data,
        ], 'activity');

        return $role->fresh();
    }

    /**
     * Eliminar un rol
     *
     * @param  string  $roleId  ID del rol
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el rol no existe
     * @throws \Exception Si el rol tiene usuarios asignados
     */
    public function delete(string $roleId): bool
    {
        $role = $this->find($roleId);

        // Verificar si tiene usuarios asignados
        if ($role->users()->count() > 0) {
            throw new \Exception("No se puede eliminar el rol '{$role->name}' porque tiene usuarios asignados");
        }

        $roleName = $role->name;
        $deleted = $role->delete();

        // Limpiar cache
        $this->clearCache($roleId);

        // Log de auditoría
        LogService::info('Rol eliminado', [
            'role_id' => $roleId,
            'role_name' => $roleName,
        ], 'activity');

        return $deleted;
    }

    /**
     * Buscar un rol por ID
     *
     * @param  string  $roleId  ID del rol
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el rol no existe
     */
    public function find(string $roleId): Role
    {
        $cacheKey = self::CACHE_PREFIX.$roleId;

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () use ($roleId) {
            return Role::with('permissions')->findOrFail($roleId);
        });
    }

    /**
     * Buscar un rol por nombre
     *
     * @param  string  $name  Nombre del rol
     */
    public function findByName(string $name): ?Role
    {
        $cacheKey = self::CACHE_PREFIX.'name:'.$name;

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () use ($name) {
            return Role::where('name', $name)->first();
        });
    }

    /**
     * Listar todos los roles con paginación
     *
     * @param  array  $filters  Filtros ['search', 'per_page']
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $perPage = $filters['per_page'] ?? 15;
        $search = $filters['search'] ?? null;

        $query = Role::with('permissions');

        // Aplicar búsqueda si existe
        if ($search) {
            $query->search($search);
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Obtener todos los roles sin paginación
     */
    public function all(): Collection
    {
        $cacheKey = self::CACHE_PREFIX.'all';

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () {
            return Role::with('permissions')->orderBy('name')->get();
        });
    }

    /**
     * Asignar un permiso a un rol
     *
     * @param  string  $roleId  ID del rol
     * @param  string  $permissionId  ID o nombre del permiso
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el rol o permiso no existe
     */
    public function assignPermission(string $roleId, string $permissionId): Role
    {
        $role = $this->find($roleId);

        // Buscar permiso por ID o nombre
        $permission = is_numeric($permissionId) || strlen($permissionId) === 36
            ? Permission::findOrFail($permissionId)
            : Permission::where('name', $permissionId)->firstOrFail();

        // Asignar permiso si no lo tiene
        if (! $role->hasPermission($permission->name)) {
            $role->assignPermission($permission);

            // Limpiar cache
            $this->clearCache($roleId);

            // Log de auditoría
            LogService::info('Permiso asignado a rol', [
                'role_id' => $roleId,
                'role_name' => $role->name,
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
            ], 'activity');
        }

        return $role->fresh(['permissions']);
    }

    /**
     * Remover un permiso de un rol
     *
     * @param  string  $roleId  ID del rol
     * @param  string  $permissionId  ID o nombre del permiso
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el rol o permiso no existe
     */
    public function removePermission(string $roleId, string $permissionId): Role
    {
        $role = $this->find($roleId);

        // Buscar permiso por ID o nombre
        $permission = is_numeric($permissionId) || strlen($permissionId) === 36
            ? Permission::findOrFail($permissionId)
            : Permission::where('name', $permissionId)->firstOrFail();

        // Remover permiso
        $role->removePermission($permission);

        // Limpiar cache
        $this->clearCache($roleId);

        // Log de auditoría
        LogService::info('Permiso removido de rol', [
            'role_id' => $roleId,
            'role_name' => $role->name,
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
        ], 'activity');

        return $role->fresh(['permissions']);
    }

    /**
     * Sincronizar permisos de un rol (reemplaza todos los permisos)
     *
     * @param  string  $roleId  ID del rol
     * @param  array  $permissionIds  Array de IDs o nombres de permisos
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el rol no existe
     */
    public function syncPermissions(string $roleId, array $permissionIds): Role
    {
        $role = $this->find($roleId);

        // Convertir nombres a IDs si es necesario
        $permissionIds = array_map(function ($permissionId) {
            if (is_numeric($permissionId) || strlen($permissionId) === 36) {
                return $permissionId;
            }
            $permission = Permission::where('name', $permissionId)->first();

            return $permission ? $permission->id : null;
        }, $permissionIds);

        // Filtrar nulls
        $permissionIds = array_filter($permissionIds);

        // Sincronizar permisos
        $role->permissions()->sync($permissionIds);

        // Limpiar cache
        $this->clearCache($roleId);

        // Log de auditoría
        LogService::info('Permisos sincronizados en rol', [
            'role_id' => $roleId,
            'role_name' => $role->name,
            'permission_count' => count($permissionIds),
        ], 'activity');

        return $role->fresh(['permissions']);
    }

    /**
     * Obtener permisos de un rol
     *
     * @param  string  $roleId  ID del rol
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el rol no existe
     */
    public function getPermissions(string $roleId): Collection
    {
        $role = $this->find($roleId);

        return $role->permissions;
    }

    /**
     * Verificar si un rol tiene un permiso específico
     *
     * @param  string  $roleId  ID del rol
     * @param  string  $permissionName  Nombre del permiso
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el rol no existe
     */
    public function hasPermission(string $roleId, string $permissionName): bool
    {
        $role = $this->find($roleId);

        return $role->hasPermission($permissionName);
    }

    /**
     * Limpiar cache de roles
     *
     * @param  string|null  $roleId  ID específico del rol o null para limpiar todo
     */
    protected function clearCache(?string $roleId = null): void
    {
        if ($roleId) {
            CacheService::forget(self::CACHE_PREFIX.$roleId);
            $role = Role::find($roleId);
            if ($role) {
                CacheService::forget(self::CACHE_PREFIX.'name:'.$role->name);
            }
        }

        // Limpiar cache de lista completa
        CacheService::forget(self::CACHE_PREFIX.'all');
    }
}

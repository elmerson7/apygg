<?php

namespace App\Services;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * PermissionService
 *
 * Servicio para gestión de permisos del sistema RBAC:
 * - CRUD completo de permisos
 * - Validaciones
 * - Búsqueda y filtrado
 */
class PermissionService
{
    /**
     * Cache TTL para permisos (en segundos)
     */
    protected const CACHE_TTL = 3600; // 1 hora

    /**
     * Cache key prefix
     */
    protected const CACHE_PREFIX = 'permission:';

    /**
     * Crear un nuevo permiso
     *
     * @param  array  $data  Datos del permiso ['name', 'display_name', 'resource', 'action', 'description']
     *
     * @throws \InvalidArgumentException Si el nombre ya existe o los datos son inválidos
     */
    public function create(array $data): Permission
    {
        // Validar datos requeridos
        $this->validatePermissionData($data);

        // Validar que el nombre sea único
        if (Permission::where('name', $data['name'])->exists()) {
            throw new \InvalidArgumentException("El permiso '{$data['name']}' ya existe");
        }

        // Validar formato de nombre (debe ser resource.action)
        if (! $this->validateNameFormat($data['name'])) {
            throw new \InvalidArgumentException(
                "El nombre del permiso debe seguir el formato 'recurso.accion' (ej: 'users.create')"
            );
        }

        $permission = Permission::create([
            'name' => $data['name'],
            'display_name' => $data['display_name'] ?? $data['name'],
            'resource' => $data['resource'] ?? $this->extractResource($data['name']),
            'action' => $data['action'] ?? $this->extractAction($data['name']),
            'description' => $data['description'] ?? null,
        ]);

        // Limpiar cache
        $this->clearCache();

        // Log de auditoría
        LogService::info('Permiso creado', [
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
        ], 'activity');

        return $permission;
    }

    /**
     * Actualizar un permiso existente
     *
     * @param  string  $permissionId  ID del permiso
     * @param  array  $data  Datos a actualizar
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el permiso no existe
     * @throws \InvalidArgumentException Si los datos son inválidos
     */
    public function update(string $permissionId, array $data): Permission
    {
        $permission = $this->find($permissionId);

        // Validar formato de nombre si se está cambiando
        if (isset($data['name']) && $data['name'] !== $permission->name) {
            // Validar que el nombre sea único
            if (Permission::where('name', $data['name'])->where('id', '!=', $permissionId)->exists()) {
                throw new \InvalidArgumentException("El permiso '{$data['name']}' ya existe");
            }

            // Validar formato
            if (! $this->validateNameFormat($data['name'])) {
                throw new \InvalidArgumentException(
                    "El nombre del permiso debe seguir el formato 'recurso.accion' (ej: 'users.create')"
                );
            }
        }

        $permission->update($data);

        // Limpiar cache
        $this->clearCache($permissionId);

        // Log de auditoría
        LogService::info('Permiso actualizado', [
            'permission_id' => $permissionId,
            'permission_name' => $permission->name,
            'changes' => $data,
        ], 'activity');

        return $permission->fresh();
    }

    /**
     * Eliminar un permiso
     *
     * @param  string  $permissionId  ID del permiso
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el permiso no existe
     * @throws \Exception Si el permiso está asignado a roles
     */
    public function delete(string $permissionId): bool
    {
        $permission = $this->find($permissionId);

        // Verificar si está asignado a roles
        if ($permission->roles()->count() > 0) {
            throw new \Exception(
                "No se puede eliminar el permiso '{$permission->name}' porque está asignado a roles"
            );
        }

        $permissionName = $permission->name;
        $deleted = $permission->delete();

        // Limpiar cache
        $this->clearCache($permissionId);

        // Log de auditoría
        LogService::info('Permiso eliminado', [
            'permission_id' => $permissionId,
            'permission_name' => $permissionName,
        ], 'activity');

        return $deleted;
    }

    /**
     * Buscar un permiso por ID
     *
     * @param  string  $permissionId  ID del permiso
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el permiso no existe
     */
    public function find(string $permissionId): Permission
    {
        $cacheKey = self::CACHE_PREFIX.$permissionId;

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () use ($permissionId) {
            return Permission::with('roles')->findOrFail($permissionId);
        });
    }

    /**
     * Buscar un permiso por nombre
     *
     * @param  string  $name  Nombre del permiso
     */
    public function findByName(string $name): ?Permission
    {
        $cacheKey = self::CACHE_PREFIX.'name:'.$name;

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () use ($name) {
            return Permission::where('name', $name)->first();
        });
    }

    /**
     * Listar todos los permisos con paginación
     *
     * @param  array  $filters  Filtros ['search', 'resource', 'action', 'per_page']
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $perPage = $filters['per_page'] ?? 15;
        $search = $filters['search'] ?? null;
        $resource = $filters['resource'] ?? null;
        $action = $filters['action'] ?? null;

        $query = Permission::with('roles');

        // Aplicar búsqueda si existe
        if ($search) {
            $query->search($search);
        }

        // Aplicar filtro por recurso
        if ($resource) {
            $query->forResource($resource);
        }

        // Aplicar filtro por acción
        if ($action) {
            $query->forAction($action);
        }

        return $query->orderBy('resource')->orderBy('action')->paginate($perPage);
    }

    /**
     * Obtener todos los permisos sin paginación
     */
    public function all(): Collection
    {
        $cacheKey = self::CACHE_PREFIX.'all';

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () {
            return Permission::with('roles')->orderBy('resource')->orderBy('action')->get();
        });
    }

    /**
     * Obtener permisos por recurso
     *
     * @param  string  $resource  Nombre del recurso
     */
    public function getByResource(string $resource): Collection
    {
        $cacheKey = self::CACHE_PREFIX.'resource:'.$resource;

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () use ($resource) {
            return Permission::forResource($resource)->orderBy('action')->get();
        });
    }

    /**
     * Obtener permisos por acción
     *
     * @param  string  $action  Nombre de la acción
     */
    public function getByAction(string $action): Collection
    {
        $cacheKey = self::CACHE_PREFIX.'action:'.$action;

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () use ($action) {
            return Permission::forAction($action)->orderBy('resource')->get();
        });
    }

    /**
     * Obtener permisos por recurso y acción
     *
     * @param  string  $resource  Nombre del recurso
     * @param  string  $action  Nombre de la acción
     */
    public function getByResourceAndAction(string $resource, string $action): Collection
    {
        $cacheKey = self::CACHE_PREFIX.'resource:'.$resource.':action:'.$action;

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () use ($resource, $action) {
            return Permission::forResourceAndAction($resource, $action)->get();
        });
    }

    /**
     * Validar datos del permiso
     *
     * @param  array  $data  Datos a validar
     *
     * @throws \InvalidArgumentException Si los datos son inválidos
     */
    public function validatePermissionData(array $data): void
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('El nombre del permiso es requerido');
        }
    }

    /**
     * Validar formato del nombre del permiso (debe ser resource.action)
     *
     * @param  string  $name  Nombre del permiso
     */
    public function validateNameFormat(string $name): bool
    {
        // Formato esperado: recurso.accion (ej: users.create, posts.update)
        return (bool) preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $name);
    }

    /**
     * Validar que el nombre del permiso sea único
     *
     * @param  string  $name  Nombre del permiso
     * @param  string|null  $excludeId  ID a excluir de la validación (para updates)
     */
    public function validateNameUnique(string $name, ?string $excludeId = null): bool
    {
        $query = Permission::where('name', $name);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return ! $query->exists();
    }

    /**
     * Validar recurso y acción
     *
     * @param  string  $resource  Nombre del recurso
     * @param  string  $action  Nombre de la acción
     */
    public function validateResourceAction(string $resource, string $action): bool
    {
        // Validar que el recurso y acción sean válidos (solo letras, números y guiones bajos)
        $resourceValid = (bool) preg_match('/^[a-z][a-z0-9_]*$/', $resource);
        $actionValid = (bool) preg_match('/^[a-z][a-z0-9_]*$/', $action);

        return $resourceValid && $actionValid;
    }

    /**
     * Extraer el recurso del nombre del permiso
     *
     * @param  string  $name  Nombre del permiso (formato: resource.action)
     */
    protected function extractResource(string $name): string
    {
        $parts = explode('.', $name);

        return $parts[0];
    }

    /**
     * Extraer la acción del nombre del permiso
     *
     * @param  string  $name  Nombre del permiso (formato: resource.action)
     */
    protected function extractAction(string $name): string
    {
        $parts = explode('.', $name);

        return $parts[1] ?? '';
    }

    /**
     * Limpiar cache de permisos
     *
     * @param  string|null  $permissionId  ID específico del permiso o null para limpiar todo
     */
    protected function clearCache(?string $permissionId = null): void
    {
        if ($permissionId) {
            CacheService::forget(self::CACHE_PREFIX.$permissionId);
            $permission = Permission::find($permissionId);
            if ($permission) {
                CacheService::forget(self::CACHE_PREFIX.'name:'.$permission->name);
                if ($permission->resource) {
                    CacheService::forget(self::CACHE_PREFIX.'resource:'.$permission->resource);
                }
                if ($permission->action) {
                    CacheService::forget(self::CACHE_PREFIX.'action:'.$permission->action);
                }
            }
        }

        // Limpiar cache de lista completa
        CacheService::forget(self::CACHE_PREFIX.'all');
    }
}

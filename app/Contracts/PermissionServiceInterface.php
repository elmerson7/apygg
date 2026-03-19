<?php

namespace App\Contracts;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * PermissionServiceInterface
 *
 * Contrato para el servicio de gestión de permisos.
 */
interface PermissionServiceInterface
{
    /**
     * Crear un nuevo permiso
     *
     * @param  array  $data  Datos del permiso ['name', 'display_name', 'resource', 'action', 'description']
     * @return Permission
     */
    public function create(array $data): Permission;

    /**
     * Actualizar un permiso existente
     *
     * @param  string  $permissionId  ID del permiso
     * @param  array  $data  Datos a actualizar
     * @return Permission
     */
    public function update(string $permissionId, array $data): Permission;

    /**
     * Eliminar un permiso
     *
     * @param  string  $permissionId  ID del permiso
     * @return bool
     */
    public function delete(string $permissionId): bool;

    /**
     * Buscar un permiso por ID
     *
     * @param  string  $permissionId  ID del permiso
     * @return Permission
     */
    public function find(string $permissionId): Permission;

    /**
     * Buscar un permiso por nombre
     *
     * @param  string  $name  Nombre del permiso
     * @return Permission|null
     */
    public function findByName(string $name): ?Permission;

    /**
     * Listar todos los permisos con paginación
     *
     * @param  array  $filters  Filtros ['search', 'resource', 'action', 'per_page']
     * @return LengthAwarePaginator
     */
    public function list(array $filters = []): LengthAwarePaginator;

    /**
     * Obtener todos los permisos sin paginación
     *
     * @return Collection
     */
    public function all(): Collection;

    /**
     * Obtener permisos por recurso
     *
     * @param  string  $resource  Nombre del recurso
     * @return Collection
     */
    public function getByResource(string $resource): Collection;

    /**
     * Obtener permisos por acción
     *
     * @param  string  $action  Nombre de la acción
     * @return Collection
     */
    public function getByAction(string $action): Collection;

    /**
     * Obtener permisos por recurso y acción
     *
     * @param  string  $resource  Nombre del recurso
     * @param  string  $action  Nombre de la acción
     * @return Collection
     */
    public function getByResourceAndAction(string $resource, string $action): Collection;

    /**
     * Validar datos del permiso
     *
     * @param  array  $data  Datos a validar
     * @return void
     */
    public function validatePermissionData(array $data): void;

    /**
     * Validar formato del nombre del permiso (debe ser resource.action)
     *
     * @param  string  $name  Nombre del permiso
     * @return bool
     */
    public function validateNameFormat(string $name): bool;

    /**
     * Validar que el nombre del permiso sea único
     *
     * @param  string  $name  Nombre del permiso
     * @param  string|null  $excludeId  ID a excluir de la validación (para updates)
     * @return bool
     */
    public function validateNameUnique(string $name, ?string $excludeId = null): bool;

    /**
     * Validar recurso y acción
     *
     * @param  string  $resource  Nombre del recurso
     * @param  string  $action  Nombre de la acción
     * @return bool
     */
    public function validateResourceAction(string $resource, string $action): bool;
}
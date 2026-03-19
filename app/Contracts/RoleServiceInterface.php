<?php

namespace App\Contracts;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * RoleServiceInterface
 *
 * Contrato para el servicio de gestión de roles.
 */
interface RoleServiceInterface
{
    /**
     * Crear un nuevo rol
     *
     * @param  array  $data  Datos del rol ['name', 'display_name', 'description']
     * @return Role
     */
    public function create(array $data): Role;

    /**
     * Actualizar un rol existente
     *
     * @param  string  $roleId  ID del rol
     * @param  array  $data  Datos a actualizar
     * @return Role
     */
    public function update(string $roleId, array $data): Role;

    /**
     * Eliminar un rol
     *
     * @param  string  $roleId  ID del rol
     * @return bool
     */
    public function delete(string $roleId): bool;

    /**
     * Buscar un rol por ID
     *
     * @param  string  $roleId  ID del rol
     * @return Role
     */
    public function find(string $roleId): Role;

    /**
     * Buscar un rol por nombre
     *
     * @param  string  $name  Nombre del rol
     * @return Role|null
     */
    public function findByName(string $name): ?Role;

    /**
     * Listar todos los roles con paginación
     *
     * @param  array  $filters  Filtros ['search', 'per_page']
     * @return LengthAwarePaginator
     */
    public function list(array $filters = []): LengthAwarePaginator;

    /**
     * Obtener todos los roles sin paginación
     *
     * @return Collection
     */
    public function all(): Collection;

    /**
     * Asignar un permiso a un rol
     *
     * @param  string  $roleId  ID del rol
     * @param  string  $permissionId  ID o nombre del permiso
     * @return Role
     */
    public function assignPermission(string $roleId, string $permissionId): Role;

    /**
     * Remover un permiso de un rol
     *
     * @param  string  $roleId  ID del rol
     * @param  string  $permissionId  ID o nombre del permiso
     * @return Role
     */
    public function removePermission(string $roleId, string $permissionId): Role;

    /**
     * Sincronizar permisos de un rol (reemplaza todos los permisos)
     *
     * @param  string  $roleId  ID del rol
     * @param  array  $permissionIds  Array de IDs o nombres de permisos
     * @return Role
     */
    public function syncPermissions(string $roleId, array $permissionIds): Role;

    /**
     * Obtener permisos de un rol
     *
     * @param  string  $roleId  ID del rol
     * @return Collection
     */
    public function getPermissions(string $roleId): Collection;

    /**
     * Verificar si un rol tiene un permiso específico
     *
     * @param  string  $roleId  ID del rol
     * @param  string  $permissionName  Nombre del permiso
     * @return bool
     */
    public function hasPermission(string $roleId, string $permissionName): bool;
}
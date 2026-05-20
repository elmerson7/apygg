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
     */
    public function create(array $data): Role;

    /**
     * Actualizar un rol existente
     *
     * @param  string  $roleId  ID del rol
     * @param  array  $data  Datos a actualizar
     */
    public function update(string $roleId, array $data): Role;

    /**
     * Eliminar un rol
     *
     * @param  string  $roleId  ID del rol
     */
    public function delete(string $roleId): bool;

    /**
     * Buscar un rol por ID
     *
     * @param  string  $roleId  ID del rol
     */
    public function find(string $roleId): Role;

    /**
     * Buscar un rol por nombre
     *
     * @param  string  $name  Nombre del rol
     */
    public function findByName(string $name): ?Role;

    /**
     * Listar todos los roles con paginación
     *
     * @param  array  $filters  Filtros ['search', 'per_page']
     */
    public function list(array $filters = []): LengthAwarePaginator;

    /**
     * Obtener todos los roles sin paginación
     */
    public function all(): Collection;

    /**
     * Asignar un permiso a un rol
     *
     * @param  string  $roleId  ID del rol
     * @param  string  $permissionId  ID o nombre del permiso
     */
    public function assignPermission(string $roleId, string $permissionId): Role;

    /**
     * Remover un permiso de un rol
     *
     * @param  string  $roleId  ID del rol
     * @param  string  $permissionId  ID o nombre del permiso
     */
    public function removePermission(string $roleId, string $permissionId): Role;

    /**
     * Sincronizar permisos de un rol (reemplaza todos los permisos)
     *
     * @param  string  $roleId  ID del rol
     * @param  array  $permissionIds  Array de IDs o nombres de permisos
     */
    public function syncPermissions(string $roleId, array $permissionIds): Role;

    /**
     * Obtener permisos de un rol
     *
     * @param  string  $roleId  ID del rol
     */
    public function getPermissions(string $roleId): Collection;

    /**
     * Verificar si un rol tiene un permiso específico
     *
     * @param  string  $roleId  ID del rol
     * @param  string  $permissionName  Nombre del permiso
     */
    public function hasPermission(string $roleId, string $permissionName): bool;
}

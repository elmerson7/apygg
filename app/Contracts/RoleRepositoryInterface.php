<?php

namespace App\Contracts;

use App\Models\Role;
use Illuminate\Support\Collection;

/**
 * RoleRepositoryInterface
 *
 * Contrato para el repositorio de roles.
 */
interface RoleRepositoryInterface
{
    /**
     * Obtener todos los roles
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*']);

    /**
     * Obtener un rol por ID
     *
     * @param  mixed  $id  ID del rol
     * @param  array  $columns  Columnas a seleccionar
     * @return Role
     */
    public function find($id, $columns = ['*']);

    /**
     * Crear un nuevo rol
     *
     * @param  array  $data  Datos del rol
     * @return Role
     */
    public function create(array $data);

    /**
     * Actualizar un rol
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del rol
     * @return Role
     */
    public function update(array $data, $id);

    /**
     * Eliminar un rol
     *
     * @param  mixed  $id  ID del rol
     * @return bool
     */
    public function delete($id);

    /**
     * Eliminar roles por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where);

    /**
     * Obtener roles por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*']);

    /**
     * Obtener el primer rol que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Role|null
     */
    public function whereFirst(array $where, $columns = ['*']);

    /**
     * Obtener rol por nombre
     *
     * @param  string  $name  Nombre del rol
     * @return Role|null
     */
    public function findByName(string $name);

    /**
     * Obtener permisos de un rol
     *
     * @param  mixed  $roleId  ID del rol
     * @return Collection
     */
    public function getPermissions($roleId);

    /**
     * Verificar si un rol tiene un permiso específico
     *
     * @param  mixed  $roleId  ID del rol
     * @param  string  $permissionName  Nombre del permiso
     * @return bool
     */
    public function hasPermission($roleId, string $permissionName): bool;
}
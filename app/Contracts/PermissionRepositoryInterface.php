<?php

namespace App\Contracts;

use App\Models\Permission;
use Illuminate\Support\Collection;

/**
 * PermissionRepositoryInterface
 *
 * Contrato para el repositorio de permisos.
 */
interface PermissionRepositoryInterface
{
    /**
     * Obtener todos los permisos
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*']);

    /**
     * Obtener un permiso por ID
     *
     * @param  mixed  $id  ID del permiso
     * @param  array  $columns  Columnas a seleccionar
     * @return Permission
     */
    public function find($id, $columns = ['*']);

    /**
     * Crear un nuevo permiso
     *
     * @param  array  $data  Datos del permiso
     * @return Permission
     */
    public function create(array $data);

    /**
     * Actualizar un permiso
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del permiso
     * @return Permission
     */
    public function update(array $data, $id);

    /**
     * Eliminar un permiso
     *
     * @param  mixed  $id  ID del permiso
     * @return bool
     */
    public function delete($id);

    /**
     * Eliminar permisos por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where);

    /**
     * Obtener permisos por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*']);

    /**
     * Obtener el primer permiso que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Permission|null
     */
    public function whereFirst(array $where, $columns = ['*']);

    /**
     * Obtener permisos por recurso
     *
     * @param  string  $resource  Nombre del recurso
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getByResource(string $resource, $columns = ['*']);

    /**
     * Obtener permisos por acción
     *
     * @param  string  $action  Nombre de la acción
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getByAction(string $action, $columns = ['*']);

    /**
     * Obtener permisos por recurso y acción
     *
     * @param  string  $resource  Nombre del recurso
     * @param  string  $action  Nombre de la acción
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getByResourceAndAction(string $resource, string $action, $columns = ['*']);
}
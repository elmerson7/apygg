<?php

namespace App\Repositories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * PermissionRepository
 *
 * Repositorio para gestionar permisos.
 */
class PermissionRepository implements RepositoryInterface
{
    /**
     * Obtener todos los permisos
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*'])
    {
        return Permission::get($columns);
    }

    /**
     * Obtener un permiso por ID
     *
     * @param  mixed  $id  ID del permiso
     * @param  array  $columns  Columnas a seleccionar
     * @return Permission
     *
     * @throws ModelNotFoundException
     */
    public function find($id, $columns = ['*'])
    {
        return Permission::findOrFail($id, $columns);
    }

    /**
     * Crear un nuevo permiso
     *
     * @param  array  $data  Datos del permiso
     * @return Permission
     */
    public function create(array $data)
    {
        return Permission::create($data);
    }

    /**
     * Actualizar un permiso
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del permiso
     * @return Permission
     */
    public function update(array $data, $id)
    {
        $permission = Permission::findOrFail($id);
        $permission->update($data);
        return $permission->fresh();
    }

    /**
     * Eliminar un permiso
     *
     * @param  mixed  $id  ID del permiso
     * @return bool
     */
    public function delete($id)
    {
        return Permission::destroy($id) > 0;
    }

    /**
     * Eliminar permisos por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where)
    {
        return Permission::where($where)->delete();
    }

    /**
     * Obtener permisos por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*'])
    {
        $query = Permission::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->get($columns);
    }

    /**
     * Obtener el primer permiso que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Permission|null
     */
    public function whereFirst(array $where, $columns = ['*'])
    {
        $query = Permission::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->first($columns);
    }

    /**
     * Obtener permisos por recurso
     *
     * @param  string  $resource  Nombre del recurso
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getByResource(string $resource, $columns = ['*'])
    {
        return Permission::where('resource', $resource)->get($columns);
    }

    /**
     * Obtener permisos por acción
     *
     * @param  string  $action  Nombre de la acción
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getByAction(string $action, $columns = ['*'])
    {
        return Permission::where('action', $action)->get($columns);
    }

    /**
     * Obtener permisos por recurso y acción
     *
     * @param  string  $resource  Nombre del recurso
     * @param  string  $action  Nombre de la acción
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getByResourceAndAction(string $resource, string $action, $columns = ['*'])
    {
        return Permission::where('resource', $resource)
            ->where('action', $action)
            ->get($columns);
    }
}
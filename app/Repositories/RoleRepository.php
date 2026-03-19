<?php

namespace App\Repositories;

use App\Models\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * RoleRepository
 *
 * Repositorio para gestionar roles.
 */
class RoleRepository implements RepositoryInterface
{
    /**
     * Obtener todos los roles
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*'])
    {
        return Role::get($columns);
    }

    /**
     * Obtener un rol por ID
     *
     * @param  mixed  $id  ID del rol
     * @param  array  $columns  Columnas a seleccionar
     * @return Role
     *
     * @throws ModelNotFoundException
     */
    public function find($id, $columns = ['*'])
    {
        return Role::findOrFail($id, $columns);
    }

    /**
     * Crear un nuevo rol
     *
     * @param  array  $data  Datos del rol
     * @return Role
     */
    public function create(array $data)
    {
        return Role::create($data);
    }

    /**
     * Actualizar un rol
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del rol
     * @return Role
     */
    public function update(array $data, $id)
    {
        $role = Role::findOrFail($id);
        $role->update($data);
        return $role->fresh();
    }

    /**
     * Eliminar un rol
     *
     * @param  mixed  $id  ID del rol
     * @return bool
     */
    public function delete($id)
    {
        return Role::destroy($id) > 0;
    }

    /**
     * Eliminar roles por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where)
    {
        return Role::where($where)->delete();
    }

    /**
     * Obtener roles por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*'])
    {
        $query = Role::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->get($columns);
    }

    /**
     * Obtener el primer rol que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Role|null
     */
    public function whereFirst(array $where, $columns = ['*'])
    {
        $query = Role::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->first($columns);
    }

    /**
     * Obtener rol por nombre
     *
     * @param  string  $name  Nombre del rol
     * @return Role|null
     */
    public function findByName(string $name)
    {
        return Role::where('name', $name)->first();
    }

    /**
     * Obtener permisos de un rol
     *
     * @param  mixed  $roleId  ID del rol
     * @return Collection
     */
    public function getPermissions($roleId)
    {
        $role = $this->find($roleId);
        return $role->permissions;
    }

    /**
     * Verificar si un rol tiene un permiso específico
     *
     * @param  mixed  $roleId  ID del rol
     * @param  string  $permissionName  Nombre del permiso
     * @return bool
     */
    public function hasPermission($roleId, string $permissionName): bool
    {
        $role = $this->find($roleId);
        return $role->hasPermission($permissionName);
    }
}
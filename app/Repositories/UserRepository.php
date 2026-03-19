<?php

namespace App\Repositories;

use App\Models\User;
use App\Contracts\UserRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * UserRepository
 *
 * Repositorio para gestionar usuarios.
 */
class UserRepository implements UserRepositoryInterface
{
    /**
     * Obtener todos los usuarios
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*'])
    {
        return User::get($columns);
    }

    /**
     * Obtener un usuario por ID
     *
     * @param  mixed  $id  ID del usuario
     * @param  array  $columns  Columnas a seleccionar
     * @return User
     *
     * @throws ModelNotFoundException
     */
    public function find($id, $columns = ['*'])
    {
        return User::findOrFail($id, $columns);
    }

    /**
     * Crear un nuevo usuario
     *
     * @param  array  $data  Datos del usuario
     * @return User
     */
    public function create(array $data)
    {
        return User::create($data);
    }

    /**
     * Actualizar un usuario
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del usuario
     * @return User
     */
    public function update(array $data, $id)
    {
        $user = User::findOrFail($id);
        $user->update($data);
        return $user->fresh();
    }

    /**
     * Eliminar un usuario (soft delete)
     *
     * @param  mixed  $id  ID del usuario
     * @return bool
     */
    public function delete($id)
    {
        return User::destroy($id) > 0;
    }

    /**
     * Eliminar usuarios por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where)
    {
        return User::where($where)->delete();
    }

    /**
     * Obtener usuarios por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*'])
    {
        $query = User::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->get($columns);
    }

    /**
     * Obtener el primer usuario que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return User|null
     */
    public function whereFirst(array $where, $columns = ['*'])
    {
        $query = User::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->first($columns);
    }

    /**
     * Obtener usuario por email
     *
     * @param  string  $email  Email del usuario
     * @return User|null
     */
    public function findByEmail(string $email)
    {
        return User::where('email', $email)->first();
    }

    /**
     * Obtener usuario por documento de identidad
     *
     * @param  string  $identityDocument  Documento de identidad
     * @return User|null
     */
    public function findByIdentityDocument(string $identityDocument)
    {
        return User::where('identity_document', $identityDocument)->first();
    }
}
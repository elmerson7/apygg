<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * UserRepositoryInterface
 *
 * Contrato para el repositorio de usuarios.
 */
interface UserRepositoryInterface
{
    /**
     * Obtener todos los usuarios
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*']);

    /**
     * Obtener un usuario por ID
     *
     * @param  mixed  $id  ID del usuario
     * @param  array  $columns  Columnas a seleccionar
     * @return User
     */
    public function find($id, $columns = ['*']);

    /**
     * Crear un nuevo usuario
     *
     * @param  array  $data  Datos del usuario
     * @return User
     */
    public function create(array $data);

    /**
     * Actualizar un usuario
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del usuario
     * @return User
     */
    public function update(array $data, $id);

    /**
     * Eliminar un usuario
     *
     * @param  mixed  $id  ID del usuario
     * @return bool
     */
    public function delete($id);

    /**
     * Eliminar usuarios por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where);

    /**
     * Obtener usuarios por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*']);

    /**
     * Obtener el primer usuario que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return User|null
     */
    public function whereFirst(array $where, $columns = ['*']);

    /**
     * Obtener usuario por email
     *
     * @param  string  $email  Email del usuario
     * @return User|null
     */
    public function findByEmail(string $email);

    /**
     * Obtener usuario por documento de identidad
     *
     * @param  string  $identityDocument  Documento de identidad
     * @return User|null
     */
    public function findByIdentityDocument(string $identityDocument);
}
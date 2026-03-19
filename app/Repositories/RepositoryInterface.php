<?php

namespace App\Repositories;

/**
 * RepositoryInterface
 *
 * Interfaz base para todos los repositorios.
 */
interface RepositoryInterface
{
    /**
     * Obtener todos los registros
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return mixed
     */
    public function all($columns = ['*']);

    /**
     * Obtener un registro por ID
     *
     * @param  mixed  $id  ID del registro
     * @param  array  $columns  Columnas a seleccionar
     * @return mixed
     */
    public function find($id, $columns = ['*']);

    /**
     * Crear un nuevo registro
     *
     * @param  array  $data  Datos del registro
     * @return mixed
     */
    public function create(array $data);

    /**
     * Actualizar un registro
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del registro
     * @return mixed
     */
    public function update(array $data, $id);

    /**
     * Eliminar un registro
     *
     * @param  mixed  $id  ID del registro
     * @return bool
     */
    public function delete($id);

    /**
     * Eliminar registros por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where);

    /**
     * Obtener registros por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return mixed
     */
    public function where(array $where, $columns = ['*']);

    /**
     * Obtener el primer registro que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return mixed
     */
    public function whereFirst(array $where, $columns = ['*']);
}
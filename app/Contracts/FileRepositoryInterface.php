<?php

namespace App\Contracts;

use App\Models\File;
use Illuminate\Support\Collection;

/**
 * FileRepositoryInterface
 *
 * Contrato para el repositorio de archivos.
 */
interface FileRepositoryInterface
{
    /**
     * Obtener todos los archivos
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*']);

    /**
     * Obtener un archivo por ID
     *
     * @param  mixed  $id  ID del archivo
     * @param  array  $columns  Columnas a seleccionar
     * @return File
     */
    public function find($id, $columns = ['*']);

    /**
     * Crear un nuevo archivo
     *
     * @param  array  $data  Datos del archivo
     * @return File
     */
    public function create(array $data);

    /**
     * Actualizar un archivo
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del archivo
     * @return File
     */
    public function update(array $data, $id);

    /**
     * Eliminar un archivo
     *
     * @param  mixed  $id  ID del archivo
     * @return bool
     */
    public function delete($id);

    /**
     * Eliminar archivos por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where);

    /**
     * Obtener archivos por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*']);

    /**
     * Obtener el primer archivo que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return File|null
     */
    public function whereFirst(array $where, $columns = ['*']);

    /**
     * Obtener archivos por tipo
     *
     * @param  string  $type  Tipo de archivo
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getByType(string $type, $columns = ['*']);

    /**
     * Obtener archivos por categoría
     *
     * @param  string  $category  Categoría de archivo
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getByCategory(string $category, $columns = ['*']);

    /**
     * Obtener archivos públicos
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getPublic($columns = ['*']);

    /**
     * Obtener archivos privados
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getPrivate($columns = ['*']);

    /**
     * Obtener archivos expirados
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getExpired($columns = ['*']);

    /**
     * Obtener archivos no expirados
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getNotExpired($columns = ['*']);

    /**
     * Obtener archivos por usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function findByUser(string $userId, $columns = ['*']);
}
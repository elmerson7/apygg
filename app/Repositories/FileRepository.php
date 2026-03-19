<?php

namespace App\Repositories;

use App\Models\File;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * FileRepository
 *
 * Repositorio para gestionar archivos.
 */
class FileRepository implements RepositoryInterface
{
    /**
     * Obtener todos los archivos
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*'])
    {
        return File::all($columns);
    }

    /**
     * Obtener un archivo por ID
     *
     * @param  mixed  $id  ID del archivo
     * @param  array  $columns  Columnas a seleccionar
     * @return File
     *
     * @throws ModelNotFoundException
     */
    public function find($id, $columns = ['*'])
    {
        return File::findOrFail($id, $columns);
    }

    /**
     * Crear un nuevo archivo
     *
     * @param  array  $data  Datos del archivo
     * @return File
     */
    public function create(array $data)
    {
        return File::create($data);
    }

    /**
     * Actualizar un archivo
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del archivo
     * @return File
     */
    public function update(array $data, $id)
    {
        $file = File::findOrFail($id);
        $file->update($data);
        return $file->fresh();
    }

    /**
     * Eliminar un archivo
     *
     * @param  mixed  $id  ID del archivo
     * @return bool
     */
    public function delete($id)
    {
        return File::destroy($id) > 0;
    }

    /**
     * Eliminar archivos por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where)
    {
        return File::where($where)->delete();
    }

    /**
     * Obtener archivos por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*'])
    {
        $query = File::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->get($columns);
    }

    /**
     * Obtener el primer archivo que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return File|null
     */
    public function whereFirst(array $where, $columns = ['*'])
    {
        $query = File::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->first($columns);
    }

    /**
     * Obtener archivos por tipo
     *
     * @param  string  $type  Tipo de archivo
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getByType(string $type, $columns = ['*'])
    {
        return File::where('type', $type)->get($columns);
    }

    /**
     * Obtener archivos por categoría
     *
     * @param  string  $category  Categoría de archivo
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getByCategory(string $category, $columns = ['*'])
    {
        return File::where('category', $category)->get($columns);
    }

    /**
     * Obtener archivos públicos
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getPublic($columns = ['*'])
    {
        return File::where('is_public', true)->get($columns);
    }

    /**
     * Obtener archivos privados
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getPrivate($columns = ['*'])
    {
        return File::where('is_public', false)->get($columns);
    }

    /**
     * Obtener archivos expirados
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getExpired($columns = ['*'])
    {
        return File::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get($columns);
    }

    /**
     * Obtener archivos no expirados
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getNotExpired($columns = ['*'])
    {
        return File::where(function ($query) {
            $query->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now());
        })->get($columns);
    }

    /**
     * Obtener archivos por usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function findByUser(string $userId, $columns = ['*'])
    {
        return File::where('user_id', $userId)->get($columns);
    }
}
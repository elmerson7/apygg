<?php

namespace App\Repositories;

use App\Models\ApiKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * ApiKeyRepository
 *
 * Repositorio para gestionar API Keys.
 */
class ApiKeyRepository implements RepositoryInterface
{
    /**
     * Obtener todas las API Keys
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*'])
    {
        return ApiKey::get($columns);
    }

    /**
     * Obtener una API Key por ID
     *
     * @param  mixed  $id  ID de la API Key
     * @param  array  $columns  Columnas a seleccionar
     * @return ApiKey
     *
     * @throws ModelNotFoundException
     */
    public function find($id, $columns = ['*'])
    {
        return ApiKey::findOrFail($id, $columns);
    }

    /**
     * Crear una nueva API Key
     *
     * @param  array  $data  Datos de la API Key
     * @return ApiKey
     */
    public function create(array $data)
    {
        return ApiKey::create($data);
    }

    /**
     * Actualizar una API Key
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID de la API Key
     * @return ApiKey
     */
    public function update(array $data, $id)
    {
        $apiKey = ApiKey::findOrFail($id);
        $apiKey->update($data);
        return $apiKey->fresh();
    }

    /**
     * Eliminar una API Key
     *
     * @param  mixed  $id  ID de la API Key
     * @return bool
     */
    public function delete($id)
    {
        return ApiKey::destroy($id) > 0;
    }

    /**
     * Eliminar API Keys por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where)
    {
        return ApiKey::where($where)->delete();
    }

    /**
     * Obtener API Keys por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*'])
    {
        $query = ApiKey::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->get($columns);
    }

    /**
     * Obtener la primera API Key que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return ApiKey|null
     */
    public function whereFirst(array $where, $columns = ['*'])
    {
        $query = ApiKey::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->first($columns);
    }

    /**
     * Obtener API Keys de un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function findByUser(string $userId, $columns = ['*'])
    {
        return ApiKey::where('user_id', $userId)->get($columns);
    }

    /**
     * Validar una API Key
     *
     * @param  string  $key  Key completa (con prefijo)
     * @return ApiKey|null
     */
    public function validate(string $key)
    {
        // Hash de la key
        $hashedKey = hash('sha256', $key);
        
        // Buscar en base de datos
        $apiKey = ApiKey::where('key', $hashedKey)
            ->whereNull('deleted_at')
            ->first();

        if (! $apiKey) {
            return null;
        }

        // Verificar expiración
        if ($apiKey->isExpired()) {
            return null;
        }

        return $apiKey;
    }
}
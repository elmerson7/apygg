<?php

namespace App\Repositories;

use App\Models\Webhook;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * WebhookRepository
 *
 * Repositorio para gestionar webhooks.
 */
class WebhookRepository implements RepositoryInterface
{
    /**
     * Obtener todos los webhooks
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*'])
    {
        return Webhook::all($columns);
    }

    /**
     * Obtener un webhook por ID
     *
     * @param  mixed  $id  ID del webhook
     * @param  array  $columns  Columnas a seleccionar
     * @return Webhook
     *
     * @throws ModelNotFoundException
     */
    public function find($id, $columns = ['*'])
    {
        return Webhook::findOrFail($id, $columns);
    }

    /**
     * Crear un nuevo webhook
     *
     * @param  array  $data  Datos del webhook
     * @return Webhook
     */
    public function create(array $data)
    {
        return Webhook::create($data);
    }

    /**
     * Actualizar un webhook
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del webhook
     * @return Webhook
     */
    public function update(array $data, $id)
    {
        $webhook = Webhook::findOrFail($id);
        $webhook->update($data);
        return $webhook->fresh();
    }

    /**
     * Eliminar un webhook
     *
     * @param  mixed  $id  ID del webhook
     * @return bool
     */
    public function delete($id)
    {
        return Webhook::destroy($id) > 0;
    }

    /**
     * Eliminar webhooks por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where)
    {
        return Webhook::where($where)->delete();
    }

    /**
     * Obtener webhooks por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*'])
    {
        $query = Webhook::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->get($columns);
    }

    /**
     * Obtener el primer webhook que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Webhook|null
     */
    public function whereFirst(array $where, $columns = ['*'])
    {
        $query = Webhook::query();
        
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        
        return $query->first($columns);
    }

    /**
     * Obtener webhooks activos que escuchan un evento específico
     *
     * @param  string  $eventType  Tipo de evento a filtrar
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getWebhooksForEvent(string $eventType, $columns = ['*'])
    {
        return Webhook::where(function ($query) use ($eventType) {
            $query->whereNull('events')
                ->orWhereJsonContains('events', $eventType);
        })->where('status', 'active')->get($columns);
    }

    /**
     * Obtener webhooks por usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function findByUser(string $userId, $columns = ['*'])
    {
        return Webhook::where('user_id', $userId)->get($columns);
    }
}
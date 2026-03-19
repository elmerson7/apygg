<?php

namespace App\Contracts;

use App\Models\Webhook;
use Illuminate\Support\Collection;

/**
 * WebhookRepositoryInterface
 *
 * Contrato para el repositorio de webhooks.
 */
interface WebhookRepositoryInterface
{
    /**
     * Obtener todos los webhooks
     *
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function all($columns = ['*']);

    /**
     * Obtener un webhook por ID
     *
     * @param  mixed  $id  ID del webhook
     * @param  array  $columns  Columnas a seleccionar
     * @return Webhook
     */
    public function find($id, $columns = ['*']);

    /**
     * Crear un nuevo webhook
     *
     * @param  array  $data  Datos del webhook
     * @return Webhook
     */
    public function create(array $data);

    /**
     * Actualizar un webhook
     *
     * @param  array  $data  Datos a actualizar
     * @param  mixed  $id  ID del webhook
     * @return Webhook
     */
    public function update(array $data, $id);

    /**
     * Eliminar un webhook
     *
     * @param  mixed  $id  ID del webhook
     * @return bool
     */
    public function delete($id);

    /**
     * Eliminar webhooks por condiciones
     *
     * @param  array  $where  Condiciones
     * @return int
     */
    public function deleteWhere(array $where);

    /**
     * Obtener webhooks por condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function where(array $where, $columns = ['*']);

    /**
     * Obtener el primer webhook que coincida con las condiciones
     *
     * @param  array  $where  Condiciones
     * @param  array  $columns  Columnas a seleccionar
     * @return Webhook|null
     */
    public function whereFirst(array $where, $columns = ['*']);

    /**
     * Obtener webhooks activos que escuchan un evento específico
     *
     * @param  string  $eventType  Tipo de evento a filtrar
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function getWebhooksForEvent(string $eventType, $columns = ['*']);

    /**
     * Obtener webhooks por usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $columns  Columnas a seleccionar
     * @return Collection
     */
    public function findByUser(string $userId, $columns = ['*']);
}
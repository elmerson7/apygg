<?php

namespace App\Contracts;

use App\Exceptions\BusinessLogicException;
use App\Models\Settings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * SettingsServiceInterface
 *
 * Contrato del servicio de configuraciones de la aplicación.
 */
interface SettingsServiceInterface
{
    /**
     * Crear un nuevo setting
     *
     * @param  array<string, mixed>  $data  Datos del setting
     * @return Settings Setting creado
     *
     * @throws BusinessLogicException Si el tipo o valor no son válidos
     */
    public function create(array $data): Settings;

    /**
     * Actualizar un setting existente
     *
     * @param  string  $id  ID del setting
     * @param  array<string, mixed>  $data  Datos a actualizar
     * @return Settings Setting actualizado
     *
     * @throws ModelNotFoundException Si el setting no existe
     * @throws BusinessLogicException Si el tipo o valor no son válidos
     */
    public function update(string $id, array $data): Settings;

    /**
     * Eliminar un setting (soft delete)
     *
     * @param  string  $id  ID del setting
     * @return bool True si se eliminó correctamente
     *
     * @throws ModelNotFoundException Si el setting no existe
     */
    public function delete(string $id): bool;

    /**
     * Buscar setting por ID
     *
     * @param  string  $id  ID del setting
     * @return Settings Setting encontrado
     *
     * @throws ModelNotFoundException Si el setting no existe
     */
    public function find(string $id): Settings;

    /**
     * Buscar setting por key con cache
     *
     * @param  string  $key  Key del setting
     * @return Settings|null Setting encontrado o null
     */
    public function findByKey(string $key): ?Settings;

    /**
     * Obtener setting por key con valor por defecto
     *
     * @param  string  $key  Key del setting
     * @param  mixed  $default  Valor por defecto si no existe
     * @return mixed Valor del setting o valor por defecto
     */
    public function getByKey(string $key, $default = null);

    /**
     * Obtener todos los settings de un grupo
     *
     * @param  string  $group  Grupo a buscar
     * @return Collection<int, mixed> Colección de settings
     */
    public function getByGroup(string $group): Collection;

    /**
     * Obtener solo settings públicos
     *
     * @return Collection<int, mixed> Colección de settings públicos
     */
    public function getPublic(): Collection;

    /**
     * Listar settings con filtros y paginación
     *
     * @param  array<string, mixed>  $filters  Filtros a aplicar
     * @return LengthAwarePaginator<int, Settings> Resultados paginados
     */
    public function list(array $filters = []): LengthAwarePaginator;

    /**
     * Encriptar valor
     *
     * @param  string  $value  Valor a encriptar
     * @return string Valor encriptado
     */
    public function encryptValue(string $value): string;

    /**
     * Desencriptar valor
     *
     * @param  string  $encryptedValue  Valor encriptado
     * @return string Valor desencriptado
     *
     * @throws \Exception Si no se puede desencriptar
     */
    public function decryptValue(string $encryptedValue): string;

    /**
     * Validar que el valor coincida con el tipo especificado
     *
     * @param  mixed  $value  Valor a validar
     * @param  string  $type  Tipo esperado
     * @return bool True si es válido
     *
     * @throws BusinessLogicException Si el tipo o valor no son válidos
     */
    public function validateType(mixed $value, string $type): bool;

    /**
     * Limpiar cache de un setting específico
     *
     * @param  string  $key  Key del setting a limpiar
     */
    public function clearCache(string $key): void;
}

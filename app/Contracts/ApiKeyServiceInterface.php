<?php

namespace App\Contracts;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use DateTimeInterface;

/**
 * ApiKeyServiceInterface
 *
 * Contrato para el servicio de gestión de API Keys.
 */
interface ApiKeyServiceInterface
{
    /**
     * Crear una nueva API Key
     *
     * @param  User  $user  Usuario propietario de la key
     * @param  string  $name  Nombre descriptivo
     * @param  array  $scopes  Scopes permitidos
     * @param  DateTimeInterface|null  $expiresAt  Fecha de expiración
     * @param  string  $environment  Entorno (live o test)
     * @return array Array con la key completa y el modelo ApiKey
     */
    public function create(User $user, string $name, array $scopes = [], ?DateTimeInterface $expiresAt = null, string $environment = 'live'): array;

    /**
     * Actualizar una API Key
     *
     * @param  string  $keyId  ID de la key
     * @param  array  $data  Datos a actualizar
     * @return ApiKey
     */
    public function update(string $keyId, array $data): ApiKey;

    /**
     * Eliminar (revocar) una API Key
     *
     * @param  string  $keyId  ID de la key
     * @return bool
     */
    public function revoke(string $keyId): bool;

    /**
     * Rotar una API Key (crear nueva y mantener antigua por período de gracia)
     *
     * @param  string  $keyId  ID de la key a rotar
     * @param  string|null  $newName  Nombre para la nueva key (opcional)
     * @param  array|null  $newScopes  Scopes para la nueva key (opcional)
     * @param  string  $environment  Entorno (live o test)
     * @return array Array con la nueva key completa y el modelo ApiKey
     */
    public function rotate(string $keyId, ?string $newName = null, ?array $newScopes = null, string $environment = 'live'): array;

    /**
     * Validar una API Key
     *
     * @param  string  $key  Key completa (con prefijo)
     * @return ApiKey|null ApiKey si es válida, null si no
     */
    public function validate(string $key): ?ApiKey;

    /**
     * Buscar una API Key por ID
     *
     * @param  string  $keyId  ID de la key
     * @return ApiKey
     */
    public function find(string $keyId): ApiKey;

    /**
     * Listar API Keys de un usuario
     *
     * @param  User  $user  Usuario propietario
     * @param  int  $perPage  Elementos por página
     * @return LengthAwarePaginator
     */
    public function list(User $user, int $perPage = 20): LengthAwarePaginator;

    /**
     * Verificar si una key tiene un scope específico
     *
     * @param  ApiKey  $apiKey  API Key
     * @param  string  $scope  Scope a verificar
     * @return bool
     */
    public function hasScope(ApiKey $apiKey, string $scope): bool;
}
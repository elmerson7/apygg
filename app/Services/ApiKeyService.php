<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * ApiKeyService
 *
 * Servicio para gestión de API Keys:
 * - CRUD completo de API Keys
 * - Rotación de keys
 * - Validación de keys
 * - Gestión de scopes
 */
class ApiKeyService
{
    /**
     * Cache TTL para validaciones de API Keys (en segundos)
     */
    protected const CACHE_TTL = 300; // 5 minutos

    /**
     * Cache key prefix
     */
    protected const CACHE_PREFIX = 'api_key:';

    /**
     * Período de gracia para rotación (en días)
     */
    protected const ROTATION_GRACE_PERIOD_DAYS = 7;

    /**
     * Crear una nueva API Key
     *
     * @param  User  $user  Usuario propietario de la key
     * @param  string  $name  Nombre descriptivo
     * @param  array  $scopes  Scopes permitidos
     * @param  \DateTimeInterface|null  $expiresAt  Fecha de expiración
     * @param  string  $environment  Entorno (live o test)
     * @return array Array con la key completa y el modelo ApiKey
     *
     * @throws \InvalidArgumentException Si los scopes no son válidos
     */
    public function create(User $user, string $name, array $scopes = [], ?\DateTimeInterface $expiresAt = null, string $environment = 'live'): array
    {
        // Validar scopes
        $this->validateScopes($scopes);

        // Generar key completa con prefijo
        $prefix = config('api-keys.prefixes.'.$environment, 'apygg_live_');
        $randomKey = Str::random(config('api-keys.key_length', 64));
        $fullKey = $prefix.$randomKey;

        // Hash de la key completa
        $hashedKey = hash('sha256', $fullKey);

        // Crear API Key
        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => $name,
            'key' => $hashedKey,
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ]);

        // Limpiar cache del usuario
        $this->clearUserCache($user->id);

        // Registrar en ActivityLog
        LogService::logActivity('created', ApiKey::class, $apiKey->id, [
            'name' => $name,
            'environment' => $environment,
            'scopes' => $scopes,
        ]);

        return [
            'api_key' => $apiKey,
            'key' => $fullKey, // Solo se retorna una vez
        ];
    }

    /**
     * Actualizar una API Key
     *
     * @param  string  $keyId  ID de la key
     * @param  array  $data  Datos a actualizar
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si la key no existe
     */
    public function update(string $keyId, array $data): ApiKey
    {
        $apiKey = ApiKey::findOrFail($keyId);

        // Validar scopes si se proporcionan
        if (isset($data['scopes'])) {
            $this->validateScopes($data['scopes']);
        }

        // Guardar valores anteriores para auditoría
        $oldValues = $apiKey->only(['name', 'scopes', 'expires_at']);

        // Actualizar
        $apiKey->update($data);

        // Limpiar cache
        $this->clearKeyCache($apiKey->key);
        $this->clearUserCache($apiKey->user_id);

        // Registrar en ActivityLog
        LogService::logActivity('updated', ApiKey::class, $apiKey->id, [
            'before' => $oldValues,
            'after' => $apiKey->only(['name', 'scopes', 'expires_at']),
        ]);

        return $apiKey->fresh();
    }

    /**
     * Eliminar (revocar) una API Key
     *
     * @param  string  $keyId  ID de la key
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si la key no existe
     */
    public function revoke(string $keyId): bool
    {
        $apiKey = ApiKey::findOrFail($keyId);

        // Limpiar cache antes de eliminar
        $this->clearKeyCache($apiKey->key);
        $this->clearUserCache($apiKey->user_id);

        // Soft delete
        $deleted = $apiKey->delete();

        // Registrar en ActivityLog
        LogService::logActivity('deleted', ApiKey::class, $apiKey->id, [
            'name' => $apiKey->name,
        ]);

        return $deleted;
    }

    /**
     * Rotar una API Key (crear nueva y mantener antigua por período de gracia)
     *
     * @param  string  $keyId  ID de la key a rotar
     * @param  string|null  $newName  Nombre para la nueva key (opcional)
     * @param  array|null  $newScopes  Scopes para la nueva key (opcional)
     * @param  string  $environment  Entorno (live o test)
     * @return array Array con la nueva key completa y el modelo ApiKey
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si la key no existe
     */
    public function rotate(string $keyId, ?string $newName = null, ?array $newScopes = null, string $environment = 'live'): array
    {
        $oldApiKey = ApiKey::findOrFail($keyId);
        $user = $oldApiKey->user;

        if (! $user || ! ($user instanceof User)) {
            throw new \RuntimeException('El usuario asociado a la API Key no existe');
        }

        // Usar valores de la key antigua si no se proporcionan nuevos
        $name = $newName ?? $oldApiKey->name.' (rotated)';
        $scopes = $newScopes ?? $oldApiKey->scopes;
        $expiresAt = $oldApiKey->expires_at;

        // Crear nueva key
        $result = $this->create($user, $name, $scopes, $expiresAt, $environment);
        $newApiKey = $result['api_key'];

        // Establecer expiración de la key antigua al período de gracia
        $gracePeriodExpiresAt = now()->addDays(self::ROTATION_GRACE_PERIOD_DAYS);
        $oldApiKey->update([
            'expires_at' => $gracePeriodExpiresAt,
        ]);

        // Registrar en ActivityLog
        LogService::logActivity('updated', ApiKey::class, $oldApiKey->id, [
            'action' => 'rotated',
            'old_key_id' => $oldApiKey->id,
            'new_key_id' => $newApiKey->id,
            'grace_period_expires_at' => $gracePeriodExpiresAt->toIso8601String(),
        ]);

        return [
            'api_key' => $newApiKey,
            'key' => $result['key'],
            'old_key_expires_at' => $gracePeriodExpiresAt->toIso8601String(),
        ];
    }

    /**
     * Validar una API Key
     *
     * @param  string  $key  Key completa (con prefijo)
     * @return ApiKey|null ApiKey si es válida, null si no
     */
    public function validate(string $key): ?ApiKey
    {
        // Hash de la key
        $hashedKey = hash('sha256', $key);

        // Buscar en cache primero
        $cacheKey = self::CACHE_PREFIX.$hashedKey;
        $cached = CacheService::get($cacheKey);

        if ($cached !== null) {
            // Si está en cache como inválida, retornar null
            if ($cached === false) {
                return null;
            }

            // Si está en cache como válida, buscar el modelo
            $apiKey = ApiKey::find($cached);
            if ($apiKey && $apiKey->isActive()) {
                return $apiKey;
            }
        }

        // Buscar en base de datos
        $apiKey = ApiKey::where('key', $hashedKey)
            ->whereNull('deleted_at')
            ->first();

        if (! $apiKey) {
            // Cachear como inválida
            CacheService::set($cacheKey, false, self::CACHE_TTL);

            return null;
        }

        // Verificar expiración
        if ($apiKey->isExpired()) {
            // Cachear como inválida
            CacheService::set($cacheKey, false, self::CACHE_TTL);

            return null;
        }

        // Cachear como válida
        CacheService::set($cacheKey, $apiKey->id, self::CACHE_TTL);

        return $apiKey;
    }

    /**
     * Buscar una API Key por ID
     *
     * @param  string  $keyId  ID de la key
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si la key no existe
     */
    public function find(string $keyId): ApiKey
    {
        return ApiKey::findOrFail($keyId);
    }

    /**
     * Listar API Keys de un usuario
     *
     * @param  User  $user  Usuario propietario
     * @param  int  $perPage  Elementos por página
     */
    public function list(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return ApiKey::byUser($user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Verificar si una key tiene un scope específico
     *
     * @param  ApiKey  $apiKey  API Key
     * @param  string  $scope  Scope a verificar
     */
    public function hasScope(ApiKey $apiKey, string $scope): bool
    {
        return $apiKey->hasScope($scope);
    }

    /**
     * Validar formato de scopes
     *
     * @param  array  $scopes  Array de scopes
     *
     * @throws \InvalidArgumentException Si algún scope no es válido
     */
    protected function validateScopes(array $scopes): void
    {
        $availableScopes = config('api-keys.available_scopes', []);

        foreach ($scopes as $scope) {
            // Permitir wildcard
            if ($scope === '*') {
                continue;
            }

            // Validar formato resource.action
            if (! preg_match('/^[a-z0-9_-]+\.[a-z0-9_-]+$/', $scope)) {
                throw new \InvalidArgumentException("El scope '{$scope}' no tiene un formato válido. Debe ser 'resource.action'");
            }

            // Validar contra lista de scopes disponibles si está configurada
            if (! empty($availableScopes) && ! in_array($scope, $availableScopes)) {
                throw new \InvalidArgumentException("El scope '{$scope}' no está disponible");
            }
        }
    }

    /**
     * Limpiar cache de una key específica
     */
    protected function clearKeyCache(string $hashedKey): void
    {
        $cacheKey = self::CACHE_PREFIX.$hashedKey;
        CacheService::forget($cacheKey);
    }

    /**
     * Limpiar cache de un usuario
     */
    protected function clearUserCache(string $userId): void
    {
        CacheService::forgetTag("user:{$userId}:api_keys");
    }
}

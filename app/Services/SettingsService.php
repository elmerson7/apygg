<?php

namespace App\Services;

use App\Models\Settings;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * SettingsService
 *
 * Servicio para gestión de Settings:
 * - CRUD completo de Settings
 * - Validación de tipos
 * - Encriptación/desencriptación de valores
 * - Gestión de cache
 * - Logging de auditoría
 */
class SettingsService
{
    /**
     * Cache TTL para settings (en segundos)
     */
    protected const CACHE_TTL = 3600; // 1 hora

    /**
     * Cache key prefix
     */
    protected const CACHE_PREFIX = 'setting:';

    /**
     * Crear un nuevo setting
     *
     * @param  array  $data  Datos del setting
     * @return Settings Setting creado
     *
     * @throws \InvalidArgumentException Si el tipo o valor no son válidos
     */
    public function create(array $data): Settings
    {
        // Validar tipo si se proporciona
        if (isset($data['type']) && isset($data['value'])) {
            $this->validateType($data['value'], $data['type']);
        }

        // Encriptar valor si is_encrypted es true
        if (isset($data['is_encrypted']) && $data['is_encrypted'] === true && isset($data['value'])) {
            $data['value'] = $this->encryptValue($data['value']);
        }

        // Crear setting
        $setting = Settings::create($data);

        // Limpiar cache
        $this->clearCache($setting->key);

        // Registrar en ActivityLog
        LogService::logActivity('created', Settings::class, $setting->id, [
            'key' => $setting->key,
            'type' => $setting->type,
            'group' => $setting->group,
        ]);

        return $setting;
    }

    /**
     * Actualizar un setting existente
     *
     * @param  string  $id  ID del setting
     * @param  array  $data  Datos a actualizar
     * @return Settings Setting actualizado
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el setting no existe
     * @throws \InvalidArgumentException Si el tipo o valor no son válidos
     */
    public function update(string $id, array $data): Settings
    {
        $setting = Settings::findOrFail($id);

        // Guardar valores anteriores para auditoría
        $oldValues = $setting->only(['key', 'value', 'type', 'group', 'is_public', 'is_encrypted']);

        // Validar tipo si se proporciona
        if (isset($data['type']) && isset($data['value'])) {
            $this->validateType($data['value'], $data['type']);
        }

        // Manejar encriptación
        $wasEncrypted = $setting->is_encrypted;
        $willBeEncrypted = $data['is_encrypted'] ?? $wasEncrypted;

        if (isset($data['value'])) {
            // Si el nuevo valor debe estar encriptado, encriptarlo
            if ($willBeEncrypted) {
                // Verificar si el valor ya está encriptado (no intentar encriptar dos veces)
                try {
                    $this->decryptValue($data['value']);
                    // Si se puede desencriptar, significa que ya está encriptado, no hacer nada
                } catch (\Exception $e) {
                    // Si no se puede desencriptar, significa que es un valor nuevo sin encriptar, encriptarlo
                    $data['value'] = $this->encryptValue($data['value']);
                }
            }
            // Si el nuevo valor NO debe estar encriptado, asegurarse de que no lo esté
            // (el valor ya viene sin encriptar del request)
        } elseif ($wasEncrypted !== $willBeEncrypted) {
            // Si no se proporciona nuevo valor pero cambia el estado de encriptación
            if ($willBeEncrypted) {
                // Cambia de no encriptado a encriptado: encriptar el valor actual
                $data['value'] = $this->encryptValue($setting->getDecryptedValue());
            } else {
                // Cambia de encriptado a no encriptado: desencriptar el valor actual
                $data['value'] = $this->decryptValue($setting->value);
            }
        }

        // Actualizar
        $setting->update($data);

        // Limpiar cache
        $this->clearCache($setting->key);

        // Registrar en ActivityLog
        LogService::logActivity('updated', Settings::class, $setting->id, [
            'before' => $oldValues,
            'after' => $setting->only(['key', 'value', 'type', 'group', 'is_public', 'is_encrypted']),
        ]);

        return $setting->fresh();
    }

    /**
     * Eliminar un setting (soft delete)
     *
     * @param  string  $id  ID del setting
     * @return bool True si se eliminó correctamente
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el setting no existe
     */
    public function delete(string $id): bool
    {
        $setting = Settings::findOrFail($id);
        $key = $setting->key;

        // Eliminar
        $deleted = $setting->delete();

        // Limpiar cache
        $this->clearCache($key);

        // Registrar en ActivityLog
        LogService::logActivity('deleted', Settings::class, $setting->id, [
            'key' => $key,
            'type' => $setting->type,
            'group' => $setting->group,
        ]);

        return $deleted;
    }

    /**
     * Buscar setting por ID
     *
     * @param  string  $id  ID del setting
     * @return Settings Setting encontrado
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si el setting no existe
     */
    public function find(string $id): Settings
    {
        return Settings::findOrFail($id);
    }

    /**
     * Buscar setting por key con cache
     *
     * @param  string  $key  Key del setting
     * @return Settings|null Setting encontrado o null
     */
    public function findByKey(string $key): ?Settings
    {
        $cacheKey = self::CACHE_PREFIX.$key;

        return CacheService::remember($cacheKey, self::CACHE_TTL, function () use ($key) {
            return Settings::byKey($key)->first();
        });
    }

    /**
     * Obtener setting por key con valor por defecto
     *
     * @param  string  $key  Key del setting
     * @param  mixed  $default  Valor por defecto si no existe
     * @return mixed Valor del setting o valor por defecto
     */
    public function getByKey(string $key, $default = null)
    {
        $setting = $this->findByKey($key);

        if (! $setting) {
            return $default;
        }

        return $setting->getTypedValue();
    }

    /**
     * Obtener todos los settings de un grupo
     *
     * @param  string  $group  Grupo a buscar
     * @return Collection Colección de settings
     */
    public function getByGroup(string $group): Collection
    {
        return Settings::byGroup($group)->get();
    }

    /**
     * Obtener solo settings públicos
     *
     * @return Collection Colección de settings públicos
     */
    public function getPublic(): Collection
    {
        return Settings::public()->get();
    }

    /**
     * Listar settings con filtros y paginación
     *
     * @param  array  $filters  Filtros a aplicar
     * @return LengthAwarePaginator Resultados paginados
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Settings::query();

        // Filtrar por grupo
        if (! empty($filters['group'])) {
            $query->byGroup($filters['group']);
        }

        // Filtrar por is_public
        if (isset($filters['is_public'])) {
            $query->where('is_public', filter_var($filters['is_public'], FILTER_VALIDATE_BOOLEAN));
        }

        // Buscar por término
        if (isset($filters['search']) && ! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Ordenamiento
        $sortField = $filters['sort'] ?? 'created_at';
        $sortDirection = $filters['direction'] ?? 'desc';
        $allowedSortFields = ['key', 'group', 'created_at', 'updated_at'];

        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, in_array($sortDirection, ['asc', 'desc']) ? $sortDirection : 'desc');
        }

        // Paginación
        $perPage = $filters['per_page'] ?? 15;
        $perPage = min(max(1, (int) $perPage), 100);

        return $query->paginate($perPage);
    }

    /**
     * Encriptar valor
     *
     * @param  string  $value  Valor a encriptar
     * @return string Valor encriptado
     */
    public function encryptValue(string $value): string
    {
        return SecurityService::encrypt($value);
    }

    /**
     * Desencriptar valor
     *
     * @param  string  $encryptedValue  Valor encriptado
     * @return string Valor desencriptado
     *
     * @throws \Exception Si no se puede desencriptar
     */
    public function decryptValue(string $encryptedValue): string
    {
        return SecurityService::decrypt($encryptedValue);
    }

    /**
     * Validar que el valor coincida con el tipo especificado
     *
     * @param  mixed  $value  Valor a validar
     * @param  string  $type  Tipo esperado
     * @return bool True si es válido
     *
     * @throws \InvalidArgumentException Si el tipo o valor no son válidos
     */
    public function validateType(mixed $value, string $type): bool
    {
        $isValid = match ($type) {
            'string' => is_string($value),
            'integer' => is_numeric($value),
            'boolean' => is_bool($value) || in_array(strtolower((string) $value), ['true', 'false', '1', '0', 'yes', 'no']),
            'json' => is_string($value) && json_decode($value) !== null && json_last_error() === JSON_ERROR_NONE,
            'array' => is_array($value) || (is_string($value) && json_decode($value) !== null && is_array(json_decode($value, true))),
            default => false,
        };

        if (! $isValid) {
            throw new \InvalidArgumentException("El valor no coincide con el tipo '{$type}' especificado");
        }

        return true;
    }

    /**
     * Limpiar cache de un setting específico
     *
     * @param  string  $key  Key del setting a limpiar
     */
    public function clearCache(string $key): void
    {
        CacheService::forget(self::CACHE_PREFIX.$key);
    }
}

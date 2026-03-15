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
    protected const CACHE_TTL = 3600;
    protected const CACHE_PREFIX = 'setting:';

    /**
     * Crear un nuevo setting
     *
     * @throws \InvalidArgumentException
     */
    public function create(array $data): Settings
    {
        if (isset($data['type']) && isset($data['value'])) {
            $this->validateType($data['value'], $data['type']);
        }

        if (isset($data['is_encrypted']) && $data['is_encrypted'] === true && isset($data['value'])) {
            $data['value'] = $this->encryptValue($data['value']);
        }

        $setting = Settings::create($data);
        $this->clearCache($setting->key);

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
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \InvalidArgumentException
     */
    public function update(string $id, array $data): Settings
    {
        $setting = Settings::findOrFail($id);
        $oldValues = $setting->only(['key', 'value', 'type', 'group', 'is_public', 'is_encrypted']);

        if (isset($data['type']) && isset($data['value'])) {
            $this->validateType($data['value'], $data['type']);
        }

        $wasEncrypted = $setting->is_encrypted;
        $willBeEncrypted = $data['is_encrypted'] ?? $wasEncrypted;

        if (isset($data['value'])) {
            if ($willBeEncrypted) {
                try {
                    $this->decryptValue($data['value']);
                } catch (\Exception $e) {
                    $data['value'] = $this->encryptValue($data['value']);
                }
            }
        } elseif ($wasEncrypted !== $willBeEncrypted) {
            $data['value'] = $willBeEncrypted
                ? $this->encryptValue($setting->getDecryptedValue())
                : $this->decryptValue($setting->value);
        }

        $setting->update($data);
        $this->clearCache($setting->key);

        LogService::logActivity('updated', Settings::class, $setting->id, [
            'before' => $oldValues,
            'after' => $setting->only(['key', 'value', 'type', 'group', 'is_public', 'is_encrypted']),
        ]);

        return $setting->fresh();
    }

    /**
     * Eliminar un setting (soft delete)
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function delete(string $id): bool
    {
        $setting = Settings::findOrFail($id);
        $key = $setting->key;
        $deleted = $setting->delete();
        $this->clearCache($key);

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
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function find(string $id): Settings
    {
        return Settings::findOrFail($id);
    }

    /**
     * Buscar setting por key con cache
     */
    public function findByKey(string $key): ?Settings
    {
        return CacheService::remember(self::CACHE_PREFIX.$key, self::CACHE_TTL, fn () => Settings::byKey($key)->first());
    }

    /**
     * Obtener valor de setting por key con default
     */
    public function getByKey(string $key, $default = null)
    {
        $setting = $this->findByKey($key);

        return $setting ? $setting->getTypedValue() : $default;
    }

    /**
     * Obtener todos los settings de un grupo
     */
    public function getByGroup(string $group): Collection
    {
        return Settings::byGroup($group)->get();
    }

    /**
     * Obtener solo settings públicos
     */
    public function getPublic(): Collection
    {
        return Settings::public()->get();
    }

    /**
     * Listar settings con filtros y paginación
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Settings::query();

        if (! empty($filters['group'])) {
            $query->byGroup($filters['group']);
        }

        if (isset($filters['is_public'])) {
            $query->where('is_public', filter_var($filters['is_public'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['search']) && ! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $sortField = $filters['sort'] ?? 'created_at';
        $sortDirection = $filters['direction'] ?? 'desc';
        $allowedSortFields = ['key', 'group', 'created_at', 'updated_at'];

        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, in_array($sortDirection, ['asc', 'desc']) ? $sortDirection : 'desc');
        }

        $perPage = min(max(1, (int) ($filters['per_page'] ?? 20)), 100);

        return $query->paginate($perPage);
    }

    public function encryptValue(string $value): string
    {
        return SecurityService::encrypt($value);
    }

    /**
     * @throws \Exception
     */
    public function decryptValue(string $encryptedValue): string
    {
        return SecurityService::decrypt($encryptedValue);
    }

    /**
     * @throws \InvalidArgumentException
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

    public function clearCache(string $key): void
    {
        CacheService::forget(self::CACHE_PREFIX.$key);
    }
}

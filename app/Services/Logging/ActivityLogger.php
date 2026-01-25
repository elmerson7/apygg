<?php

namespace App\Services\Logging;

use App\Models\Logs\ActivityLog;
use App\Services\LogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ActivityLogger
 *
 * Logger especializado para registrar cambios en modelos (auditoría).
 * Se puede usar directamente o mediante Observers para captura automática.
 */
class ActivityLogger
{
    /**
     * Campos que deben ser excluidos del log (sensibles)
     *
     * @var array<string>
     */
    protected static array $excludedFields = [
        'password',
        'password_confirmation',
        'token',
        'api_token',
        'remember_token',
        'secret',
        'key',
    ];

    /**
     * Registrar una acción en un modelo
     *
     * @param  Model  $model  Modelo afectado
     * @param  string  $action  Acción realizada (created, updated, deleted, restored)
     * @param  array|null  $oldValues  Valores anteriores (solo para updated)
     * @param  string|null  $userId  ID del usuario que realizó la acción (null = usuario autenticado)
     */
    public static function log(
        Model $model,
        string $action,
        ?array $oldValues = null,
        ?string $userId = null
    ): ?ActivityLog {
        try {
            // Obtener usuario (autenticado o especificado)
            $userId = $userId ?? Auth::id();

            // Filtrar valores sensibles
            $oldValuesFiltered = $oldValues ? self::filterSensitiveFields($oldValues) : null;
            $newValuesFiltered = self::filterSensitiveFields($model->getAttributes());

            // Obtener IP del request actual
            $ipAddress = request()->ip();

            return ActivityLog::create([
                'user_id' => $userId,
                'model_type' => get_class($model),
                'model_id' => $model->getKey(),
                'action' => $action,
                'old_values' => $oldValuesFiltered,
                'new_values' => $newValuesFiltered,
                'ip_address' => $ipAddress,
            ]);
        } catch (\Exception $e) {
            // Log del error pero no interrumpir el flujo principal
            LogService::error('Failed to log activity', [
                'model' => get_class($model),
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Registrar creación de modelo
     */
    public static function logCreated(Model $model, ?string $userId = null): ?ActivityLog
    {
        return self::log($model, ActivityLog::ACTION_CREATED, null, $userId);
    }

    /**
     * Registrar actualización de modelo
     *
     * @param  array|null  $oldValues  Valores anteriores
     */
    public static function logUpdated(Model $model, ?array $oldValues = null, ?string $userId = null): ?ActivityLog
    {
        return self::log($model, ActivityLog::ACTION_UPDATED, $oldValues, $userId);
    }

    /**
     * Registrar eliminación de modelo
     */
    public static function logDeleted(Model $model, ?string $userId = null): ?ActivityLog
    {
        return self::log($model, ActivityLog::ACTION_DELETED, null, $userId);
    }

    /**
     * Registrar restauración de modelo
     */
    public static function logRestored(Model $model, ?string $userId = null): ?ActivityLog
    {
        return self::log($model, ActivityLog::ACTION_RESTORED, null, $userId);
    }

    /**
     * Filtrar campos sensibles de los valores
     */
    protected static function filterSensitiveFields(array $values): array
    {
        return array_filter($values, function ($key) {
            return ! in_array(strtolower($key), self::$excludedFields);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Agregar campos a la lista de excluidos
     *
     * @param  array<string>  $fields
     */
    public static function excludeFields(array $fields): void
    {
        self::$excludedFields = array_merge(self::$excludedFields, $fields);
    }
}

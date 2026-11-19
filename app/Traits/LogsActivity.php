<?php

namespace App\Traits;

use App\Services\Logging\ActivityLogger;
use Illuminate\Support\Facades\Log;

/**
 * Trait LogsActivity
 *
 * Registra automáticamente las actividades (crear, actualizar, eliminar) de un modelo.
 * Usa ActivityLogger para guardar en la base de datos.
 */
trait LogsActivity
{
    /**
     * Boot the trait.
     */
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            $model->logActivity('created');
        });

        static::updated(function ($model) {
            $model->logActivity('updated', $model->getOriginal());
        });

        static::deleted(function ($model) {
            $model->logActivity('deleted');
        });

        static::restored(function ($model) {
            $model->logActivity('restored');
        });
    }

    /**
     * Registrar actividad en el log usando ActivityLogger.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $oldValues
     */
    protected function logActivity(string $action, ?array $oldValues = null): void
    {
        try {
            // Usar ActivityLogger para guardar en base de datos
            ActivityLogger::log($this, $action, $oldValues);
        } catch (\Exception $e) {
            // Silenciar errores de logging para no interrumpir el flujo principal
            Log::warning('Failed to log activity', [
                'model' => get_class($this),
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Filtrar campos sensibles antes de guardar en el log.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> mixed>
     */
    protected function filterSensitiveFields(array $data): array
    {
        $sensitiveFields = $this->getSensitiveFields();

        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '***REDACTED***';
            }
        }

        return $data;
    }

    /**
     * Obtener lista de campos sensibles a filtrar.
     * Sobrescribir en el modelo para personalizar.
     *
     * @return list<string> mixed>
     */
    protected function getSensitiveFields(): array
    {
        return [
            'password',
            'password_confirmation',
            'token',
            'api_key',
            'secret',
            'credit_card',
            'cvv',
            'ssn',
        ];
    }

    /**
     * Obtener cambios formateados para el log.
     *
     * @return array<string, mixed> mixed>
     */
    public function getFormattedChanges(): array
    {
        $changes = $this->getChanges();
        $original = $this->getOriginal();

        $formatted = [];
        foreach ($changes as $key => $value) {
            $formatted[$key] = [
                'before' => $original[$key] ?? null,
                'after' => $value,
            ];
        }

        return $formatted;
    }
}

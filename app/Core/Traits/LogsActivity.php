<?php

namespace App\Core\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Trait LogsActivity
 * 
 * Registra automáticamente las actividades (crear, actualizar, eliminar) de un modelo.
 * 
 * @package App\Core\Traits
 */
trait LogsActivity
{
    /**
     * Boot the trait.
     */
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            $model->logActivity('created', $model->getAttributes());
        });

        static::updated(function ($model) {
            $model->logActivity('updated', $model->getChanges(), $model->getOriginal());
        });

        static::deleted(function ($model) {
            $model->logActivity('deleted', $model->getAttributes());
        });
    }

    /**
     * Registrar actividad en el log.
     */
    protected function logActivity(string $action, array $after = [], array $before = []): void
    {
        // Filtrar campos sensibles
        $after = $this->filterSensitiveFields($after);
        $before = $this->filterSensitiveFields($before);

        $logData = [
            'model' => get_class($this),
            'model_id' => $this->getKey(),
            'action' => $action,
            'user_id' => Auth::id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'before' => $before,
            'after' => $after,
            'timestamp' => now()->toIso8601String(),
        ];

        // Log en canal de actividad si existe, sino en default
        if (config('logging.channels.activity')) {
            Log::channel('activity')->info("Activity: {$action}", $logData);
        } else {
            Log::info("Activity: {$action}", $logData);
        }

        // También guardar en base de datos si el modelo ActivityLog existe
        if (class_exists(\App\Models\Logs\ActivityLog::class)) {
            try {
                \App\Models\Logs\ActivityLog::create([
                    'user_id' => Auth::id(),
                    'model_type' => get_class($this),
                    'model_id' => $this->getKey(),
                    'action' => $action,
                    'before' => $before,
                    'after' => $after,
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            } catch (\Exception $e) {
                // Silenciar errores de base de datos para no interrumpir el flujo principal
                Log::warning('Failed to save activity log to database', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Filtrar campos sensibles antes de guardar en el log.
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

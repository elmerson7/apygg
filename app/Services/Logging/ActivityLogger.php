<?php

namespace App\Services\Logging;

use App\Models\Logs\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

/**
 * Activity Logger Service
 * 
 * Registra cualquier actividad importante del sistema que requiera auditoría.
 * No solo para usuarios y perfiles, sino para cualquier entidad del negocio.
 * 
 * Ejemplos de uso:
 * - ActivityLogger::log('compra.created', $userId, 'Compra', $compraId, ['monto' => 1500])
 * - ActivityLogger::log('supervisor.updated', $userId, 'Supervisor', $supervisorId, $changes)
 * - ActivityLogger::log('producto.deleted', $userId, 'Producto', $productoId, ['razon' => 'Discontinuado'])
 * - ActivityLogger::log('inventario.adjusted', $userId, 'Inventario', $itemId, ['cantidad_anterior' => 100, 'cantidad_nueva' => 85])
 */
class ActivityLogger
{
    /**
     * Log a general activity event.
     * 
     * @param string $event Tipo de evento (ej: 'compra.created', 'supervisor.updated')
     * @param string|null $userId ID del usuario que realizó la acción
     * @param string|null $subjectType Tipo de entidad afectada (ej: 'Compra', 'Supervisor')
     * @param int|null $subjectId ID específico del registro afectado
     * @param array|null $meta Datos adicionales relevantes (cambios, montos, etc.)
     * @param Request|null $request Request HTTP (opcional, se toma automáticamente)
     */
    public static function log(
        string $event,
        ?string $userId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $meta = null,
        ?Request $request = null
    ): void {
        // Verificar si el activity logging está habilitado
        if (!config('logging.activity_enabled', true)) {
            return;
        }

        $request = $request ?? request();
        
        $data = [
            'user_id' => $userId,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'meta' => $meta,
            'trace_id' => $request?->attributes->get('trace_id'),
        ];

        // Si estamos en modo asíncrono (recomendado para producción)
        if (config('logging.async', true)) {
            Queue::push(function () use ($data) {
                ActivityLog::create($data);
            });
        } else {
            // Modo síncrono para desarrollo/testing
            ActivityLog::create($data);
        }
    }

    /**
     * Log user creation.
     */
    public static function logUserCreated(string $userId, ?string $createdBy = null, ?Request $request = null): void
    {
        self::log(
            event: 'user.created',
            userId: $createdBy,
            subjectType: 'User',
            subjectId: $userId,
            meta: ['action' => 'create'],
            request: $request
        );
    }

    /**
     * Log user update.
     */
    public static function logUserUpdated(string $userId, ?string $updatedBy = null, ?array $changes = null, ?Request $request = null): void
    {
        self::log(
            event: 'user.updated',
            userId: $updatedBy,
            subjectType: 'User',
            subjectId: $userId,
            meta: ['action' => 'update', 'changes' => $changes],
            request: $request
        );
    }

    /**
     * Log user deletion.
     */
    public static function logUserDeleted(string $userId, ?string $deletedBy = null, ?Request $request = null): void
    {
        self::log(
            event: 'user.deleted',
            userId: $deletedBy,
            subjectType: 'User',
            subjectId: $userId,
            meta: ['action' => 'delete'],
            request: $request
        );
    }

    /**
     * Log profile changes.
     */
    public static function logProfileUpdated(string $userId, ?array $changes = null, ?Request $request = null): void
    {
        self::log(
            event: 'profile.updated',
            userId: $userId,
            subjectType: 'User',
            subjectId: $userId,
            meta: ['action' => 'profile_update', 'changes' => $changes],
            request: $request
        );
    }

    /**
     * Log settings changes.
     */
    public static function logSettingsChanged(string $userId, string $setting, $oldValue, $newValue, ?Request $request = null): void
    {
        self::log(
            event: 'settings.changed',
            userId: $userId,
            subjectType: 'Settings',
            subjectId: $userId,
            meta: [
                'setting' => $setting,
                'old_value' => $oldValue,
                'new_value' => $newValue
            ],
            request: $request
        );
    }

    /**
     * Log password changes.
     */
    public static function logPasswordChanged(string $userId, ?Request $request = null): void
    {
        self::log(
            event: 'password.changed',
            userId: $userId,
            subjectType: 'User',
            subjectId: $userId,
            meta: ['action' => 'password_change'],
            request: $request
        );
    }

    /**
     * Log email changes.
     */
    public static function logEmailChanged(string $userId, string $oldEmail, string $newEmail, ?Request $request = null): void
    {
        self::log(
            event: 'email.changed',
            userId: $userId,
            subjectType: 'User',
            subjectId: $userId,
            meta: [
                'old_email' => $oldEmail,
                'new_email' => $newEmail
            ],
            request: $request
        );
    }

    /**
     * Get recent activity for a user.
     */
    public static function getRecentActivityForUser(string $userId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return ActivityLog::forUser($userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get activity for a specific subject.
     */
    public static function getActivityForSubject(string $subjectType, int $subjectId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return ActivityLog::subjectType($subjectType)
            ->where('subject_id', $subjectId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get all activity by event type.
     */
    public static function getActivityByEvent(string $event, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return ActivityLog::event($event)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}

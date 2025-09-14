<?php

namespace App\Services\Logging;

use App\Models\Logs\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

class ActivityLogger
{
    /**
     * Log a general activity event.
     */
    public static function log(
        string $event,
        ?int $userId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $meta = null,
        ?Request $request = null
    ): void {
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
    public static function logUserCreated(int $userId, ?int $createdBy = null, ?Request $request = null): void
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
    public static function logUserUpdated(int $userId, ?int $updatedBy = null, ?array $changes = null, ?Request $request = null): void
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
    public static function logUserDeleted(int $userId, ?int $deletedBy = null, ?Request $request = null): void
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
    public static function logProfileUpdated(int $userId, ?array $changes = null, ?Request $request = null): void
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
    public static function logSettingsChanged(int $userId, string $setting, $oldValue, $newValue, ?Request $request = null): void
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
    public static function logPasswordChanged(int $userId, ?Request $request = null): void
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
    public static function logEmailChanged(int $userId, string $oldEmail, string $newEmail, ?Request $request = null): void
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
    public static function getRecentActivityForUser(int $userId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
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

<?php

namespace App\Repositories;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class FileRepository
{
    /**
     * Obtiene archivos del usuario
     */
    public function getUserFiles(
        User $user,
        ?string $type = null,
        ?string $status = null,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = File::where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($type) {
            $query->ofType($type);
        }

        if ($status) {
            $query->withStatus($status);
        }

        return $query->paginate($perPage);
    }

    /**
     * Obtiene archivo por ID con verificación de propietario
     */
    public function getUserFile(User $user, string $fileId): ?File
    {
        return File::where('id', $fileId)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Obtiene archivos por checksum para detectar duplicados
     */
    public function findByChecksum(string $checksum, ?User $user = null): Collection
    {
        $query = File::where('checksum', $checksum)
            ->where('status', File::STATUS_VERIFIED);

        if ($user) {
            $query->where('user_id', $user->id);
        }

        return $query->get();
    }

    /**
     * Obtiene archivos pendientes de escaneo
     */
    public function getPendingScans(int $limit = 100): Collection
    {
        return File::withStatus(File::STATUS_SCANNING)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Obtiene archivos fallidos
     */
    public function getFailedUploads(int $hours = 1): Collection
    {
        return File::withStatus(File::STATUS_UPLOADING)
            ->where('created_at', '<', now()->subHours($hours))
            ->get();
    }

    /**
     * Obtiene archivos temporales antiguos
     */
    public function getOldTempFiles(int $hours = 24): Collection
    {
        return File::ofType(File::TYPE_TEMP)
            ->where('created_at', '<', now()->subHours($hours))
            ->whereIn('status', [File::STATUS_UPLOADING, File::STATUS_FAILED])
            ->get();
    }

    /**
     * Obtiene archivos infectados antiguos
     */
    public function getOldInfectedFiles(int $days = 30): Collection
    {
        return File::withStatus(File::STATUS_INFECTED)
            ->where('updated_at', '<', now()->subDays($days))
            ->get();
    }

    /**
     * Obtiene estadísticas de archivos
     */
    public function getStats(?User $user = null): array
    {
        $query = File::query();

        if ($user) {
            $query->where('user_id', $user->id);
        }

        $stats = [
            'total' => $query->count(),
            'by_status' => $query->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'by_type' => $query->selectRaw("meta->>'type' as type, count(*) as count")
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'total_size' => $query->sum('size'),
        ];

        if ($user) {
            $stats['quota_used'] = $this->getUserQuotaUsed($user);
        }

        return $stats;
    }

    /**
     * Obtiene el tamaño total de archivos del usuario
     */
    public function getUserQuotaUsed(User $user): int
    {
        return File::where('user_id', $user->id)
            ->where('status', File::STATUS_VERIFIED)
            ->sum('size');
    }

    /**
     * Busca archivos por nombre
     */
    public function searchByName(
        string $query,
        ?User $user = null,
        int $perPage = 15
    ): LengthAwarePaginator {
        $search = File::where('original_name', 'ILIKE', "%{$query}%")
            ->orWhere('path', 'ILIKE', "%{$query}%");

        if ($user) {
            $search->where('user_id', $user->id);
        }

        return $search->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Obtiene archivos por MIME type
     */
    public function getByMimeType(
        string $mimeType,
        ?User $user = null,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = File::where('mime_type', $mimeType)
            ->where('status', File::STATUS_VERIFIED);

        if ($user) {
            $query->where('user_id', $user->id);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Obtiene archivos recientes
     */
    public function getRecent(?User $user = null, int $limit = 10): Collection
    {
        $query = File::where('status', File::STATUS_VERIFIED)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($user) {
            $query->where('user_id', $user->id);
        }

        return $query->get();
    }

    /**
     * Marca archivos como fallidos si llevan mucho tiempo en uploading
     */
    public function markStaleUploadsAsFailed(int $hours = 1): int
    {
        return File::withStatus(File::STATUS_UPLOADING)
            ->where('created_at', '<', now()->subHours($hours))
            ->update(['status' => File::STATUS_FAILED]);
    }

    /**
     * Elimina archivos soft deleted antiguos permanentemente
     */
    public function permanentlyDeleteOldFiles(int $days = 30): int
    {
        return File::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($days))
            ->forceDelete();
    }
}

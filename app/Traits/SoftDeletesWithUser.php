<?php

namespace App\Traits;

use App\Helpers\Actor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Trait SoftDeletesWithUser
 *
 * Extiende SoftDeletes nativo agregando registro del usuario que eliminó el registro.
 */
trait SoftDeletesWithUser
{
    use SoftDeletes;

    /**
     * Boot the trait.
     */
    public static function bootSoftDeletesWithUser(): void
    {
        static::deleting(function ($model) {
            // Registrar usuario que elimina antes de soft delete
            if (Actor::id() !== null && $model->isFillable('deleted_by')) {
                $model->deleted_by = Actor::id();
                $model->saveQuietly(); // Guardar sin disparar eventos
            }
        });

        static::restoring(function ($model) {
            // Limpiar deleted_by al restaurar
            if ($model->isFillable('deleted_by')) {
                $model->deleted_by = null;
            }
        });
    }

    /**
     * Initialize the trait.
     */
    public function initializeSoftDeletesWithUser(): void
    {
        // Agregar deleted_by a fillable si no está
        if (! in_array('deleted_by', $this->fillable)) {
            $this->fillable[] = 'deleted_by';
        }

        // Agregar deleted_by a casts si no está
        if (! isset($this->casts['deleted_by'])) {
            $this->casts['deleted_by'] = 'string'; // UUID
        }
    }

    /**
     * Obtener el usuario que eliminó el registro.
     *
     * @return BelongsTo<User, $this>
     */
    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Scope para filtrar registros eliminados por un usuario específico.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDeletedBy($query, string $userId)
    {
        return $query->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotNull($this->qualifyColumn('deleted_at'))
            ->where('deleted_by', $userId);
    }

    /**
     * Verificar si fue eliminado por un usuario específico.
     */
    public function wasDeletedBy(string $userId): bool
    {
        return $this->trashed() && $this->deleted_by === $userId;
    }

    /**
     * Verificar si fue eliminado por el usuario actual.
     */
    public function wasDeletedByCurrentUser(): bool
    {
        $actorId = Actor::id();

        return $actorId !== null && $this->wasDeletedBy((string) $actorId);
    }
}

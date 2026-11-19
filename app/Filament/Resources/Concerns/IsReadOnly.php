<?php

namespace App\Filament\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Recurso de solo lectura: nada de crear, editar ni borrar, tenga la policy que tenga
 * (un admin pasa Gate::after, pero este candado va aparte).
 */
trait IsReadOnly
{
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @param  Model  $record
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    /**
     * @param  Model  $record
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}

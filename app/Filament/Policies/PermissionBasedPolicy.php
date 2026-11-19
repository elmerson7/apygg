<?php

namespace App\Filament\Policies;

use App\Models\User;

/**
 * Base de las policies del back-office para modelos que la API protege solo con
 * middleware de permiso (logs, correo, lista negra): aquí el permiso de la ruta
 * pasa a ser la policy que Filament exige. Por defecto solo lectura.
 *
 * Gate::after (super admin) solo actúa cuando la policy no decide: un `false`
 * explícito también frena al admin (p. ej. editar un log).
 */
abstract class PermissionBasedPolicy
{
    /** Permiso que da lectura (el mismo de la ruta de la API). */
    abstract protected function readPermission(): string;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user);
    }

    public function view(User $user, mixed $record = null): bool
    {
        return $this->canRead($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, mixed $record = null): bool
    {
        return false;
    }

    public function delete(User $user, mixed $record = null): bool
    {
        return false;
    }

    protected function canRead(User $user): bool
    {
        return $user->can($this->readPermission());
    }
}

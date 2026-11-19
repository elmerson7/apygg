<?php

namespace App\Policies;

use App\Models\File;
use App\Models\User;

/**
 * FilePolicy
 *
 * Un archivo lo ve, descarga, edita o borra su dueño o quien tenga el permiso
 * `files.manage` (el rol admin lo pasa por el super admin). Un archivo público
 * (`is_public`) lo puede ver y descargar cualquier usuario autenticado.
 */
class FilePolicy
{
    public function view(User $user, File $file): bool
    {
        return $file->is_public || $this->owns($user, $file) || $user->can('files.manage');
    }

    public function download(User $user, File $file): bool
    {
        return $this->view($user, $file);
    }

    public function update(User $user, File $file): bool
    {
        return $this->owns($user, $file) || $user->can('files.manage');
    }

    public function delete(User $user, File $file): bool
    {
        return $this->update($user, $file);
    }

    private function owns(User $user, File $file): bool
    {
        return (string) $file->user_id === (string) $user->id;
    }
}

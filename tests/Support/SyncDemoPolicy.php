<?php

namespace Tests\Support;

use App\Models\User;

/** Policy de prueba de `offline-sync`: permite todo salvo actualizar. */
class SyncDemoPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SyncDemoItem $item): bool
    {
        return false;
    }

    public function delete(User $user, SyncDemoItem $item): bool
    {
        return true;
    }
}

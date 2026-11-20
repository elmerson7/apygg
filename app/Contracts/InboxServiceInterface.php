<?php

namespace App\Contracts;

use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * InboxServiceInterface
 *
 * Bandeja in-app de un usuario en el tenant activo. TODA operación está acotada a `$user`: un id de otro
 * usuario (o de otro tenant) es un 404, nunca un 403.
 */
interface InboxServiceInterface
{
    /**
     * @param  array{unread?: bool|null, type?: string|null}  $filters
     * @return LengthAwarePaginator<int, InboxNotification>
     */
    public function paginate(User $user, array $filters = [], ?int $perPage = null): LengthAwarePaginator;

    public function unreadCount(User $user): int;

    public function markRead(User $user, int $id): InboxNotification;

    /** @return int cuántas se marcaron */
    public function markAllRead(User $user): int;

    public function delete(User $user, int $id): void;

    /**
     * Tarea de plataforma (comando programado): borra las LEÍDAS con más de `$days` días, de todos los tenants.
     *
     * @return int borradas
     */
    public function purgeRead(int $days): int;
}

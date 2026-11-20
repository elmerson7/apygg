<?php

namespace App\Services\Notifications;

use App\Contracts\InboxServiceInterface;
use App\Models\InboxNotification;
use App\Models\User;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class InboxService implements InboxServiceInterface
{
    public function paginate(User $user, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $perPage = min(
            max(1, $perPage ?? (int) config('notifications.inbox.per_page', 20)),
            (int) config('notifications.inbox.max_per_page', 100),
        );

        return $this->mine($user)
            ->when(! empty($filters['unread']), fn (Builder $q) => $q->whereNull('read_at'))
            ->when(! empty($filters['type']), fn (Builder $q) => $q->where('type', $filters['type']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return $this->mine($user)->whereNull('read_at')->count();
    }

    public function markRead(User $user, int $id): InboxNotification
    {
        $notification = $this->mine($user)->findOrFail($id);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    public function markAllRead(User $user): int
    {
        return $this->mine($user)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
    }

    public function delete(User $user, int $id): void
    {
        $this->mine($user)->findOrFail($id)->delete();
    }

    public function purgeRead(int $days): int
    {
        return TenantQuery::withoutTenant(
            fn () => InboxNotification::query()->withTrashed()->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhere(fn ($q) => $q->whereNotNull('read_at')))->where('updated_at', '<', now()->subDays($days))->forceDelete(),
            'retención de la bandeja de notificaciones',
        );
    }

    /**
     * @return Builder<InboxNotification>
     */
    private function mine(User $user): Builder
    {
        return InboxNotification::query()->where('user_id', $user->getKey());
    }
}

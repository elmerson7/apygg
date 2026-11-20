<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificación de la bandeja in-app (canal `database`), de un usuario DENTRO de un tenant
 * (`BelongsToTenant`, fail-closed). Siempre se consulta además por `user_id`: ver `InboxService`.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $user_id
 * @property string|null $notification_id
 * @property string $type
 * @property string $title
 * @property string|null $body
 * @property array<string, mixed>|null $data
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface|null $created_at
 */
class InboxNotification extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'notification_id', 'type', 'title', 'body', 'data'];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}

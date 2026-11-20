<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dispositivo push de un usuario (docs/notifications.md). Es de la PERSONA, no de un tenant.
 * El `token` es secreto de entrega: no se expone por la API (`$hidden`) ni se registra completo.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $platform
 * @property string|null $device_id
 * @property string|null $name
 * @property CarbonInterface|null $last_seen_at
 */
class UserDevice extends EloquentModel
{
    public const PLATFORMS = ['ios', 'android', 'web'];

    protected $fillable = ['user_id', 'token', 'platform', 'device_id', 'name', 'last_seen_at'];

    protected $hidden = ['token'];

    protected $casts = ['last_seen_at' => 'datetime'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

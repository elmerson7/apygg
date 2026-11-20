<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Ajustes de notificación del usuario: horas de silencio (HH:MM en SU zona horaria).
 *
 * @property int $id
 * @property int $user_id
 * @property bool $quiet_hours_enabled
 * @property string|null $quiet_start
 * @property string|null $quiet_end
 */
class NotificationSetting extends EloquentModel
{
    protected $fillable = ['user_id', 'quiet_hours_enabled', 'quiet_start', 'quiet_end'];

    protected $casts = ['quiet_hours_enabled' => 'boolean'];
}

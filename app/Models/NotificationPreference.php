<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Opt-out/opt-in de un usuario para un tipo de notificación y un canal. Sin fila = vale el
 * valor por defecto del tipo (activado). Los tipos `mandatory` ignoran esta tabla.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $channel
 * @property bool $enabled
 */
class NotificationPreference extends EloquentModel
{
    protected $fillable = ['user_id', 'type', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}

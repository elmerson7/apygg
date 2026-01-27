<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channel Routes
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
| WebSockets es OPCIONAL. Para habilitarlo:
| 1. Configurar BROADCAST_CONNECTION=reverb en .env
| 2. Configurar variables REVERB_* en .env
| 3. Iniciar servidor Reverb: php artisan reverb:start
|
*/

// Canal público para notificaciones generales (sin autenticación)
Broadcast::channel('notifications', function () {
    return true;
});

// Canal privado por usuario (requiere autenticación JWT)
// Formato: private-user.{userId}
Broadcast::channel('private-user.{userId}', function ($user, $userId) {
    return (string) $user->id === (string) $userId;
});

// Canal de presencia para usuarios en línea
// Formato: presence-online
Broadcast::channel('presence-online', function ($user) {
    if ($user) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
});

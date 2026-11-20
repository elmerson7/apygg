<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channel Routes
|--------------------------------------------------------------------------
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

// Canal público para pruebas (sin autenticación)
Broadcast::channel('test-messages', function () {
    return true;
});

// Canal privado por usuario (requiere autenticación JWT)
Broadcast::channel('private-user.{userId}', function ($user, $userId) {
    return (string) $user->id === (string) $userId;
});

// Presencia por empresa. El cliente se une con `echo.join('tenant.{id}')` (Echo antepone `presence-`;
// el servidor normaliza el prefijo, por eso el patrón va sin él). Solo miembros ACTIVOS de ese tenant;
// el payload NO incluye el correo (cualquier compañero lo vería): id y nombre bastan.
Broadcast::channel('tenant.{tenantId}', function ($user, $tenantId) {
    if (! config('multi-tenancy.enabled', false) || ! $user instanceof User) {
        return false;
    }

    if (! $user->isActiveMemberOf((int) $tenantId)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

// Presencia de plataforma (`echo.join('online')`): solo con multiempresa APAGADO (una sola
// organización). Con multiempresa activo se deniega: usa `tenant.{id}`. Sin correo en el payload.
Broadcast::channel('online', function ($user) {
    if (config('multi-tenancy.enabled', false) || ! $user instanceof User) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

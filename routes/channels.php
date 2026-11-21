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

// No hay canales públicos: todo canal exige autenticación (JWT en POST /broadcasting/auth). Los eventos propios
// de cada proyecto van por `private-user.{id}`, `tenant.{id}` (presencia) o canales privados nuevos aquí.

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

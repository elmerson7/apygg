<?php

use App\Events\PermissionGranted;
use App\Events\PermissionRevoked;
use App\Events\RoleAssigned;
use App\Events\RoleRemoved;
use App\Events\UserCreated;
use App\Events\UserDeleted;
use App\Events\UserLoggedIn;
use App\Events\UserLoggedOut;
use App\Events\UserRestored;
use App\Events\UserUpdated;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Cuenta los listeners propios (App\Listeners) registrados para un evento,
 * normalizando cada uno a "Clase@metodo".
 *
 * @return array<int, string>
 */
function appListenersFor(string $event): array
{
    $listeners = [];
    foreach (app('events')->getRawListeners()[$event] ?? [] as $listener) {
        if (is_string($listener)) {
            $listeners[] = $listener;
        } elseif (is_array($listener) && is_string($listener[0])) {
            $listeners[] = $listener[0].'@'.$listener[1];
        }
    }

    return array_values(array_filter($listeners, fn ($l) => str_starts_with($l, 'App\\Listeners\\')));
}

dataset('listeners esperados', [
    'UserCreated' => [UserCreated::class, ['App\Listeners\LogUserActivity@handleUserCreated', 'App\Listeners\SendWelcomeEmail', 'App\Listeners\InvalidateUserCache', 'App\Listeners\WebhookListener']],
    'UserUpdated' => [UserUpdated::class, ['App\Listeners\LogUserActivity@handleUserUpdated', 'App\Listeners\InvalidateUserCache', 'App\Listeners\WebhookListener']],
    'UserDeleted' => [UserDeleted::class, ['App\Listeners\LogUserActivity@handleUserDeleted', 'App\Listeners\InvalidateUserCache', 'App\Listeners\WebhookListener']],
    'UserRestored' => [UserRestored::class, ['App\Listeners\LogUserActivity@handleUserRestored', 'App\Listeners\InvalidateUserCache', 'App\Listeners\WebhookListener']],
    'UserLoggedIn' => [UserLoggedIn::class, ['App\Listeners\LogAuthEvents@handleUserLoggedIn', 'App\Listeners\WebhookListener']],
    'UserLoggedOut' => [UserLoggedOut::class, ['App\Listeners\LogAuthEvents@handleUserLoggedOut', 'App\Listeners\WebhookListener']],
    'RoleAssigned' => [RoleAssigned::class, ['App\Listeners\InvalidatePermissionsCache', 'App\Listeners\WebhookListener']],
    'RoleRemoved' => [RoleRemoved::class, ['App\Listeners\InvalidatePermissionsCache', 'App\Listeners\WebhookListener']],
    'PermissionGranted' => [PermissionGranted::class, ['App\Listeners\InvalidatePermissionsCache', 'App\Listeners\WebhookListener']],
    'PermissionRevoked' => [PermissionRevoked::class, ['App\Listeners\InvalidatePermissionsCache', 'App\Listeners\WebhookListener']],
    'Login' => [Login::class, ['App\Listeners\LogAuthenticationEvents@handleLogin']],
    'Failed' => [Failed::class, ['App\Listeners\LogAuthenticationEvents@handleFailed']],
    'Logout' => [Logout::class, ['App\Listeners\LogAuthenticationEvents@handleLogout']],
    'PasswordReset' => [PasswordReset::class, ['App\Listeners\LogAuthenticationEvents@handlePasswordReset']],
]);

test('registra cada listener exactamente una vez, sin duplicados ni pérdidas', function (string $event, array $esperados) {
    $registrados = appListenersFor($event);

    expect($registrados)->toEqualCanonicalizing($esperados);
})->with('listeners esperados');

test('ningún evento tiene listeners propios repetidos', function () {
    $duplicados = collect(array_keys(app('events')->getRawListeners()))
        ->filter(function ($event) {
            $l = appListenersFor($event);

            return count($l) !== count(array_unique($l));
        })->values()->all();

    expect($duplicados)->toBe([]);
});

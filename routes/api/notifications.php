<?php

use App\Http\Controllers\Notifications\DeviceController;
use App\Http\Controllers\Notifications\InboxController;
use App\Http\Controllers\Notifications\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notificaciones del usuario en sesión (docs/notifications.md)
|--------------------------------------------------------------------------
|
| Perfil del usuario (`/user`): sin permiso propio, cada uno ve y toca solo lo suyo.
|   - Bandeja in-app: del usuario DENTRO del tenant activo (middleware `tenant`).
|   - Preferencias y dispositivos: de la persona, valen en todos sus tenants (sin `tenant`).
|
*/

Route::middleware(['auth:api'])->prefix('user')->group(function () {
    Route::get('notifications/preferences', [NotificationPreferenceController::class, 'show'])->name('user.notifications.preferences.show');
    Route::put('notifications/preferences', [NotificationPreferenceController::class, 'update'])->name('user.notifications.preferences.update');

    Route::get('devices', [DeviceController::class, 'index'])->name('user.devices.index');
    Route::post('devices', [DeviceController::class, 'store'])->middleware('idempotent')->name('user.devices.store');
    Route::delete('devices/{id}', [DeviceController::class, 'destroy'])->whereNumber('id')->name('user.devices.destroy');
});

Route::middleware(['auth:api', 'tenant'])->prefix('user/notifications')->group(function () {
    Route::get('/', [InboxController::class, 'index'])->name('user.notifications.index');
    Route::get('unread-count', [InboxController::class, 'unreadCount'])->name('user.notifications.unread-count');
    Route::post('read-all', [InboxController::class, 'readAll'])->name('user.notifications.read-all');
    Route::post('{id}/read', [InboxController::class, 'read'])->whereNumber('id')->name('user.notifications.read');
    Route::delete('{id}', [InboxController::class, 'destroy'])->whereNumber('id')->name('user.notifications.destroy');
});

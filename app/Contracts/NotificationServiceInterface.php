<?php

namespace App\Contracts;

use App\Exceptions\BusinessLogicException;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NotificationType;

/**
 * NotificationServiceInterface
 *
 * ÚNICO camino para avisar a usuarios por varios canales (docs/notifications.md). El tipo se declara en
 * `config/notifications.php`; el texto, en `lang/<idioma>/notifications.php`. Preferencias, horas de
 * silencio, reclamo idempotente y registro de entregas se aplican solos.
 */
interface NotificationServiceInterface
{
    /**
     * Encola la notificación (un job por destinatario, tras el commit de la transacción abierta).
     *
     * @param  User|iterable<User>  $users
     * @param  array<string, scalar|null>  $params  sustituyen `:param` en título y cuerpo (`action_url` añade un botón)
     * @param  Tenant|null  $tenant  tenant en el que se envía (por defecto, el del contexto actual)
     *
     * @throws BusinessLogicException tipo desconocido
     */
    public function notify(User|iterable $users, string $type, array $params = [], ?Tenant $tenant = null): void;

    /**
     * Igual que `notify()` pero en la misma petición (sin cola). Para comandos y pruebas.
     *
     * @param  User|iterable<User>  $users
     * @param  array<string, scalar|null>  $params
     */
    public function notifyNow(User|iterable $users, string $type, array $params = [], ?Tenant $tenant = null): void;

    /**
     * Tipos declarados, por clave.
     *
     * @return array<string, NotificationType>
     */
    public function types(): array;
}

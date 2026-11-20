<?php

namespace App\Contracts;

use App\Exceptions\BusinessLogicException;
use App\Models\User;
use App\Notifications\NotificationType;
use Carbon\CarbonInterface;

/**
 * NotificationPreferenceServiceInterface
 *
 * Opt-out por tipo y canal y horas de silencio de un usuario (en SU zona horaria, `LocaleResolver`).
 * Los tipos `mandatory` (seguridad) ignoran ambas cosas.
 */
interface NotificationPreferenceServiceInterface
{
    /**
     * Matriz completa para pintar la pantalla de ajustes.
     *
     * @return array{
     *     types: list<array{type: string, mandatory: bool, channels: array<string, bool>}>,
     *     quiet_hours: array{enabled: bool, start: string|null, end: string|null, timezone: string}
     * }
     */
    public function forUser(User $user): array;

    /**
     * @param  list<array{type: string, channel: string, enabled: bool}>  $preferences
     * @param  array{enabled?: bool, start?: string|null, end?: string|null}|null  $quietHours
     *
     * @throws BusinessLogicException tipo obligatorio, tipo/canal desconocido o franja incompleta
     */
    public function update(User $user, array $preferences = [], ?array $quietHours = null): void;

    /** ¿Puede recibir este tipo por este canal? (los obligatorios, siempre). */
    public function isEnabled(User $user, NotificationType $type, string $channel): bool;

    /** ¿Está `$at` (por defecto, ahora) dentro de sus horas de silencio? */
    public function inQuietHours(User $user, ?CarbonInterface $at = null): bool;
}

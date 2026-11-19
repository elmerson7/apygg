<?php

namespace App\Contracts;

use App\Exceptions\SettingException;
use App\Models\User;
use App\Support\Settings\SettingDefinition;

/**
 * Registro de ajustes tipados por niveles (docs/settings-and-flags.md).
 * Resolución: valor del tenant activo -> valor de plataforma (tabla `settings`) -> default de config.
 */
interface SettingsRegistryInterface
{
    /**
     * @return array<string, SettingDefinition>
     */
    public function definitions(): array;

    /**
     * Valor efectivo en el contexto actual (el tenant activo, si lo hay).
     *
     * @throws SettingException `setting_unknown` si la key no está declarada
     */
    public function get(string $key): mixed;

    /**
     * Ajustes con su valor de PLATAFORMA (override o default).
     *
     * @return list<array<string, mixed>>
     */
    public function platformView(): array;

    /**
     * Ajustes editables por el tenant activo, con su valor efectivo y de dónde sale.
     *
     * @return list<array<string, mixed>>
     */
    public function tenantView(): array;

    /**
     * @throws SettingException unknown 404 / notEditable 403 / invalid 422
     */
    public function setPlatform(string $key, mixed $value): mixed;

    /** Quita el override de plataforma (vuelve al default). */
    public function resetPlatform(string $key): void;

    /**
     * ÚNICA vía de escritura de ajustes del lado tenant: escribe el ajuste en el tenant activo en
     * nombre de `$actor` (quien hace la petición; queda en el rastro de actividad). Es el punto donde
     * se engancha la comprobación del rol del actor EN ese tenant (docs/permissions.md §Teams).
     *
     * @throws SettingException
     */
    public function setTenant(User $actor, string $key, mixed $value): mixed;

    /** Quita el override del tenant activo (vuelve a plataforma/default). Misma vía que `setTenant`. */
    public function resetTenant(User $actor, string $key): void;
}

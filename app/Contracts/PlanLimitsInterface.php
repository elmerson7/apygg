<?php

namespace App\Contracts;

use App\Exceptions\PlanLimitReachedException;
use App\Exceptions\PlanLimitUnknownException;

/**
 * Límites por plan y por tenant (docs/settings-and-flags.md §Límites). Opt-in (`PLAN_LIMITS_ENABLED`)
 * y solo con multiempresa activo: sin tenant en el contexto nada se limita. No es facturación.
 *
 * Plantilla para los módulos:
 *
 *     $limits->guarded('max_widgets', fn () => Widget::count(), fn () => Widget::create($data));
 */
interface PlanLimitsInterface
{
    /** ¿Hay límites activos (config + multiempresa)? */
    public function enabled(): bool;

    /**
     * Límite vigente: override del tenant -> plan del tenant. `null` = ilimitado (también si los
     * límites están apagados o no hay tenant).
     *
     * @throws PlanLimitUnknownException (422 `plan_limit_unknown`) si la clave no está declarada en `config/plans.php`
     */
    public function limit(string $key, ?int $tenantId = null): ?int;

    /** ¿Cabe `$adding` más sobre `$current` sin pasarse? */
    public function check(string $key, int $current, int $adding = 1, ?int $tenantId = null): bool;

    /**
     * `$unit` convierte el límite a la unidad de `$current`/`$adding` (p. ej. 1048576 para comparar bytes
     * contra un límite en MB); la excepción informa el límite ya en esa unidad.
     *
     * @throws PlanLimitReachedException 403 `plan_limit_reached`
     */
    public function ensureWithin(string $key, int $current, int $adding = 1, ?int $tenantId = null, int $unit = 1): void;

    /**
     * Cuenta, comprueba y crea EN UNA TRANSACCIÓN con la fila del tenant bloqueada (`FOR UPDATE`):
     * dos altas simultáneas se serializan y no pueden pasarse del límite. Úsalo siempre que el
     * recurso cuente contra un límite.
     *
     * @template T
     *
     * @param  \Closure(): int  $count  conteo actual (dentro de la transacción)
     * @param  \Closure(): T  $create  alta; solo se ejecuta si cabe
     * @return T
     *
     * @throws PlanLimitReachedException
     */
    public function guarded(string $key, \Closure $count, \Closure $create, int $adding = 1, int $unit = 1): mixed;

    /**
     * Plan, límites efectivos y de dónde sale cada uno, para un tenant.
     *
     * @return array<string, mixed>
     */
    public function overview(int $tenantId): array;

    /** Asigna el plan (NULL = el de por defecto). */
    public function setPlan(int $tenantId, ?string $plan): void;

    /** Override de un límite para un tenant (`null` = ilimitado). */
    public function setOverride(int $tenantId, string $key, ?int $value): void;

    public function clearOverride(int $tenantId, string $key): void;
}

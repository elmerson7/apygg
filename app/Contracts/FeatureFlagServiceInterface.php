<?php

namespace App\Contracts;

use App\Exceptions\FeatureFlagException;
use App\Models\User;

/**
 * Feature flags en BD con override por tenant/usuario y despliegue porcentual estable
 * (docs/settings-and-flags.md). Las flags se DECLARAN en `config/features.php` (valor por defecto);
 * la BD las sobrescribe sin deploy. Orden: override de usuario -> override de tenant -> flag global
 * (fila en BD o config) + porcentaje.
 */
interface FeatureFlagServiceInterface
{
    /**
     * ¿Activa para el tenant del contexto y el usuario dado (o el autenticado en `api`)? Una flag
     * no declarada está apagada.
     */
    public function enabled(string $key, ?User $user = null): bool;

    public function disabled(string $key, ?User $user = null): bool;

    /**
     * Evaluación explícita (sin contexto): útil en jobs, comandos y pruebas.
     */
    public function enabledFor(string $key, ?int $userId = null, ?int $tenantId = null): bool;

    /**
     * Todas las flags declaradas evaluadas para el tenant del contexto y el usuario.
     *
     * @return array<string, array{enabled: bool, description: string|null}>
     */
    public function all(?User $user = null): array;

    /**
     * Estado de plataforma de cada flag (config + BD + overrides), para el panel.
     *
     * @return list<array<string, mixed>>
     */
    public function platformView(): array;

    /**
     * Crea/actualiza la flag global en BD (`enabled`, `rollout_percentage`, `rollout_by`, `description`).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws FeatureFlagException
     */
    public function setFlag(string $key, array $data): array;

    /** Borra la fila de BD (vuelve al valor de config) y todos sus overrides. */
    public function resetFlag(string $key): void;

    public function setTenantOverride(string $key, int $tenantId, bool $enabled): void;

    public function clearTenantOverride(string $key, int $tenantId): void;

    public function setUserOverride(string $key, int $userId, bool $enabled): void;

    public function clearUserOverride(string $key, int $userId): void;
}

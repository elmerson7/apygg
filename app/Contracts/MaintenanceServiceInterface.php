<?php

namespace App\Contracts;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

interface MaintenanceServiceInterface
{
    /**
     * Estado actual: null si la API está operativa.
     *
     * @return array{message: string|null, until: string|null, since: string, source: string, retry_after: int}|null
     */
    public function state(): ?array;

    public function enable(?string $message, ?CarbonInterface $until, ?User $by = null): void;

    /** Desactiva el modo (solo el de caché; el forzado por entorno no se apaga desde la API). */
    public function disable(?User $by = null): void;

    /** ¿Esta petición pasa aunque haya mantenimiento (salud, webhooks, IP o rol autorizados)? */
    public function allows(Request $request): bool;
}

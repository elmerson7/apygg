<?php

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\TenancyMisconfigured;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coherencia entre `MULTI_TENANCY_ENABLED` y los datos.
 *
 * Con multiempresa APAGADO ningún scope actúa: si en la BD hay varias empresas, cualquier consulta de un modelo
 * con `BelongsToTenant` mezclaría sus datos. Eso es una configuración incoherente (se apagó la bandera por error
 * en un despliegue ya multiempresa) y debe fallar en voz alta, no servir datos cruzados.
 *
 * Con la bandera encendida no hay nada que comprobar aquí: el aislamiento es fail-closed.
 */
final class TenancyCoherence
{
    private const CACHE_KEY = 'tenancy:coherence-problems';

    /**
     * @return list<string>
     */
    public static function problems(): array
    {
        if ((bool) config('multi-tenancy.enabled', false) || ! Schema::hasTable('tenants')) {
            return [];
        }

        $query = DB::table('tenants');
        if (Schema::hasColumn('tenants', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $count = $query->count();

        return $count > 1
            ? ["MULTI_TENANCY_ENABLED=false pero hay {$count} empresas en la tabla `tenants`: sus datos no están aislados (activa la multiempresa o elimina las sobrantes)"]
            : [];
    }

    /**
     * Lanza si la configuración es incoherente. Solo con `multi-tenancy.strict` (por defecto, en producción).
     * El resultado se cachea 60 s para no consultar en cada petición.
     *
     * @throws TenancyMisconfigured
     */
    public static function assertCoherent(): void
    {
        if (! config('multi-tenancy.strict', false)) {
            return;
        }

        /** @var list<string> $problems */
        $problems = Cache::remember(self::CACHE_KEY, 60, fn (): array => self::problems());

        if ($problems !== []) {
            throw new TenancyMisconfigured(implode('; ', $problems));
        }
    }
}

<?php

namespace App\Contracts;

use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Política de acceso según el estado de la suscripción del tenant (docs/billing.md §Bloqueo por estado).
 *
 * `ResolveTenant` la consulta tras fijar el tenant: todas las rutas con `tenant` quedan cubiertas sin declarar nada
 * por ruta (fail-closed). No toca datos: solo decide si una petición pasa y con qué aviso.
 */
interface SubscriptionAccessInterface
{
    public const MODE_FULL = 'full';

    public const MODE_GRACE = 'grace';

    public const MODE_READ_ONLY = 'read_only';

    /**
     * Estado de acceso del tenant ahora mismo.
     *
     * @return array{mode: string, status: string|null, code: string|null, grace_ends_at: string|null, invoice_id: int|null, suggested_action: array{action: string, method: string, path: string}|null}
     */
    public function stateFor(int $tenantId): array;

    /**
     * Deja pasar la petición o lanza `SubscriptionBlockedException` (402). Devuelve el valor de la cabecera
     * `X-Subscription-Warning` si hay que avisar (gracia por impago) o null.
     */
    public function enforce(Request $request, Tenant $tenant): ?string;
}

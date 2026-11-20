<?php

namespace App\Models;

use App\Traits\BelongsToTenant;

/**
 * Aviso de facturación ya enviado: guarda el reclamo único (suscripción, tipo, clave) de docs/billing.md §Avisos.
 * Multiempresa fail-closed: leerlo sin tenant lanza `MissingTenantContext`. El reclamo se inserta con `insertOrIgnore`
 * (UNIQUE) pasando `tenant_id` explícito del tenant del contexto.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $subscription_id
 * @property string $type
 * @property string $period_key
 * @property int $recipients
 */
class BillingNotice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['subscription_id', 'type', 'period_key', 'recipients'];
}

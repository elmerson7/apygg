<?php

namespace App\Enums;

/**
 * Estado de un tenant (columna `tenants.status`, string).
 *
 * Solo `active` permite operar: cualquier otro valor hace que el middleware `tenant`
 * responda 403 `tenant_suspended`. Añadir un estado no exige migración (ver docs/migrations.md §4).
 */
enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    /** Alta libre pendiente de revisión de la plataforma (`tenancy:approve`). */
    case Pending = 'pending';
}

<?php

namespace Tests\Support;

use App\Models\Model;
use App\Traits\BelongsToTenant;

/**
 * Modelo SOLO para tests del núcleo de multiempresa (la tabla la crea InteractsWithTenancy).
 * El boilerplate no trae ningún modelo de negocio con tenant_id.
 */
class TenantNote extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_notes';

    protected $guarded = [];
}

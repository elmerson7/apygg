<?php

namespace Tests\Support;

use App\Models\Model;
use App\Traits\BelongsToTenant;
use App\Traits\ClientGeneratedId;

/**
 * Modelo SOLO para las pruebas del módulo `offline-sync` (la tabla la crea la prueba). El boilerplate no trae
 * ningún modelo de negocio sincronizable.
 */
class SyncDemoItem extends Model
{
    use BelongsToTenant, ClientGeneratedId;

    protected $table = 'sync_demo_items';

    protected $guarded = [];
}

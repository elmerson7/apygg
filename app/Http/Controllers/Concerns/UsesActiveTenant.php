<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Http\Request;

/**
 * El tenant de una ruta con middleware `tenant` sale SIEMPRE del contexto resuelto por el middleware
 * (`$request->attributes['tenant']`), nunca del cuerpo ni de la query.
 */
trait UsesActiveTenant
{
    protected function activeTenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');

        return $tenant instanceof Tenant ? $tenant : throw new MissingTenantContext;
    }
}

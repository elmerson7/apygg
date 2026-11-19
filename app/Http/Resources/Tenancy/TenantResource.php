<?php

namespace App\Http\Resources\Tenancy;

use App\Http\Resources\BaseResource;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * @mixin Tenant
 */
class TenantResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = $this->resource;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            // Solo si se cargó por la membresía del usuario (`GET /tenants/mine`, alta de empresa).
            'role' => $this->when($tenant->pivot !== null, fn () => $tenant->pivot->role ?? null),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}

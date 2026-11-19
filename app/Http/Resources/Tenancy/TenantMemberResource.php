<?php

namespace App\Http\Resources\Tenancy;

use App\Http\Resources\BaseResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Miembro del tenant: solo lo necesario para administrarlo (nada de campos internos del usuario).
 *
 * @mixin User
 */
class TenantMemberResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getAttribute('tenant_role'),
            'status' => $user->getAttribute('tenant_status'),
            'joined_at' => $user->getAttribute('tenant_joined_at') !== null
                ? Carbon::parse($user->getAttribute('tenant_joined_at'))->toIso8601String()
                : null,
        ];
    }
}

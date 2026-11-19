<?php

namespace App\Http\Resources\Tenancy;

use App\Http\Resources\BaseResource;
use App\Models\TenantInvitation;
use Illuminate\Http\Request;

/**
 * Invitación: nunca expone el token ni su hash (el claro solo viaja por correo).
 *
 * @mixin TenantInvitation
 */
class TenantInvitationResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invitation = $this->resource;

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'status' => $invitation->status(),
            'invited_by' => $invitation->invited_by,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}

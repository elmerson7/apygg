<?php

namespace App\Contracts;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TenantInvitationServiceInterface
{
    /**
     * @param  array<string, mixed>  $filters  status (pending|accepted|revoked|expired), search
     * @return LengthAwarePaginator<int, TenantInvitation>
     */
    public function paginate(Tenant $tenant, User $actor, array $filters, int $perPage): LengthAwarePaginator;

    public function invite(Tenant $tenant, User $inviter, string $email, string $role): TenantInvitation;

    /** Rota el token y la caducidad y reenvía el correo. */
    public function resend(Tenant $tenant, User $actor, int $invitationId): TenantInvitation;

    public function revoke(Tenant $tenant, User $actor, int $invitationId): TenantInvitation;

    /** Acepta con el token en claro: crea la membresía del usuario autenticado. */
    public function accept(User $user, string $token): Tenant;
}

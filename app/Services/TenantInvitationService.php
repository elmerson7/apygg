<?php

namespace App\Services;

use App\Contracts\AuditServiceInterface;
use App\Contracts\PlanLimitsInterface;
use App\Contracts\TenantInvitationServiceInterface;
use App\Enums\TenantMembershipStatus;
use App\Exceptions\Tenancy\TenantOnboardingException;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Support\Tenancy\Exceptions\TenantSuspended;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use App\Support\Tenancy\TenantRoles;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invitaciones a un tenant (docs/tenant-onboarding.md).
 *
 * - Token: 64 caracteres aleatorios; en BD solo su sha256 (`token_hash`, único). El claro viaja solo en el correo.
 * - Aceptar es una operación de PLATAFORMA (el invitado aún no es miembro, no hay `X-Tenant`): localiza la
 *   invitación por el hash del token (`withoutTenant`, auditado) y desde ahí trabaja DENTRO de su tenant.
 * - Una invitación pendiente por (tenant, email) y una sola membresía por (tenant, usuario): ambas por
 *   índice único, así que las carreras acaban en 409/422 y nunca en 500 ni en duplicados.
 */
class TenantInvitationService implements TenantInvitationServiceInterface
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditServiceInterface $audit,
        private readonly PlanLimitsInterface $limits,
    ) {}

    public function paginate(Tenant $tenant, User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-invitations.manage');

        $query = TenantInvitation::query()->where('tenant_id', $tenant->getKey())->with('inviter:id,name,email');

        match ($filters['status'] ?? null) {
            'pending' => $query->usable(),
            'expired' => $query->pending()->where('expires_at', '<=', now()),
            'accepted' => $query->whereNotNull('accepted_at'),
            'revoked' => $query->whereNotNull('revoked_at')->whereNull('accepted_at'),
            default => null,
        };

        if (! empty($filters['search'])) {
            $query->where('email', 'ILIKE', '%'.addcslashes(mb_strtolower((string) $filters['search']), '\\%_').'%');
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function invite(Tenant $tenant, User $inviter, string $email, string $role): TenantInvitation
    {
        $email = $this->normalize($email);
        $this->assertCanGrant($tenant, $inviter, $role);

        if ($this->isMember($tenant, $email)) {
            throw TenantOnboardingException::alreadyMember();
        }

        $token = $this->newToken();

        try {
            $invitation = DB::transaction(function () use ($tenant, $inviter, $email, $role, $token): TenantInvitation {
                // Serializa las altas de invitaciones de este tenant (tope de pendientes coherente).
                Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

                // Una pendiente CADUCADA no bloquea: se da por cerrada antes de crear la nueva.
                TenantInvitation::query()->where('tenant_id', $tenant->getKey())->pending()
                    ->where('email', $email)->where('expires_at', '<=', now())
                    ->update(['revoked_at' => now()]);

                if (TenantInvitation::query()->where('tenant_id', $tenant->getKey())->usable()->where('email', $email)->exists()) {
                    throw TenantOnboardingException::invitationAlreadyPending();
                }

                $max = (int) config('multi-tenancy.onboarding.invitations.max_pending', 0);
                if ($max > 0 && TenantInvitation::query()->where('tenant_id', $tenant->getKey())->usable()->count() >= $max) {
                    throw TenantOnboardingException::invitationLimitReached();
                }

                // `max_members` del plan: miembros (activos o suspendidos) + invitaciones pendientes, con el tenant bloqueado.
                $this->limits->ensureWithin('max_members', $this->seats($tenant), 1, (int) $tenant->getKey());

                return TenantInvitation::create([
                    'email' => $email,
                    'role' => $role,
                    'token_hash' => TenantInvitation::hashToken($token),
                    'invited_by' => $inviter->getKey(),
                    'expires_at' => $this->expiry(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw TenantOnboardingException::invitationAlreadyPending();
        }

        $this->send($tenant, $inviter, $invitation, $token);

        return $invitation;
    }

    public function resend(Tenant $tenant, User $actor, int $invitationId): TenantInvitation
    {
        $token = $this->newToken();

        $invitation = DB::transaction(function () use ($tenant, $actor, $invitationId, $token): TenantInvitation {
            $invitation = TenantInvitation::query()->where('tenant_id', $tenant->getKey())->lockForUpdate()->findOrFail($invitationId);

            $this->assertOpen($invitation, allowExpired: true);
            $this->assertCanGrant($tenant, $actor, $invitation->role);

            $invitation->forceFill([
                'token_hash' => TenantInvitation::hashToken($token),
                'expires_at' => $this->expiry(),
            ])->save();

            return $invitation;
        });

        $this->send($tenant, $actor, $invitation, $token);

        return $invitation;
    }

    public function revoke(Tenant $tenant, User $actor, int $invitationId): TenantInvitation
    {
        return DB::transaction(function () use ($tenant, $actor, $invitationId): TenantInvitation {
            $invitation = TenantInvitation::query()->where('tenant_id', $tenant->getKey())->lockForUpdate()->findOrFail($invitationId);

            if ($invitation->accepted_at !== null) {
                throw TenantOnboardingException::invitationUsed();
            }

            $this->assertCanGrant($tenant, $actor, $invitation->role);

            if ($invitation->revoked_at === null) {
                $invitation->forceFill(['revoked_at' => now()])->save();
            }

            return $invitation;
        });
    }

    public function accept(User $user, string $token): Tenant
    {
        $hash = TenantInvitation::hashToken($token);

        $found = TenantQuery::withoutTenant(
            fn () => TenantInvitation::query()->where('token_hash', $hash)->first(),
            'aceptar invitación por token',
        );

        // Mismo error si no existe, para que no se sondeen tokens; `hash_equals` por higiene (el índice ya compara).
        if (! $found instanceof TenantInvitation || ! hash_equals($found->token_hash, $hash)) {
            throw TenantOnboardingException::invitationInvalid();
        }

        $tenant = Tenant::query()->find($found->tenant_id);

        if (! $tenant instanceof Tenant) {
            throw TenantOnboardingException::invitationInvalid();
        }

        if (! $tenant->isActive()) {
            throw new TenantSuspended;
        }

        $role = $this->context->run($tenant, function () use ($user, $tenant, $found): string {
            try {
                return DB::transaction(function () use ($user, $tenant, $found): string {
                    // Bloquea la invitación: dos aceptaciones a la vez se ordenan y la segunda ve `accepted_at`.
                    $invitation = TenantInvitation::query()->lockForUpdate()->findOrFail($found->getKey());

                    $this->assertOpen($invitation);

                    if (! config('multi-tenancy.onboarding.invitations.allow_email_mismatch')
                        && ! hash_equals($invitation->email, $this->normalize($user->email))) {
                        throw TenantOnboardingException::invitationEmailMismatch();
                    }

                    if (! TenantRoles::exists($invitation->role)) {
                        throw TenantOnboardingException::invalidState('El rol de la invitación ya no existe.');
                    }

                    if (DB::table('tenant_user')->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->exists()) {
                        throw TenantOnboardingException::alreadyMember();
                    }

                    // Serializa con las altas y revisa `max_members` (tras una bajada de plan ya no caben más; nada se borra).
                    Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
                    $this->limits->ensureWithin('max_members', (int) DB::table('tenant_user')->where('tenant_id', $tenant->getKey())->count(), 1, (int) $tenant->getKey());

                    $tenant->users()->attach($user->getKey(), [
                        'status' => TenantMembershipStatus::Active->value,
                        'role' => $invitation->role,
                        'invited_by' => $invitation->invited_by,
                        'joined_at' => now(),
                    ]);

                    $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->getKey()])->save();

                    $this->audit->recordAction('tenant.member-added', $tenant, null, ['user_id' => $user->getKey(), 'role' => $invitation->role, 'invitation_id' => $invitation->getKey()], (int) $tenant->getKey());

                    return $invitation->role;
                });
            } catch (UniqueConstraintViolationException) {
                throw TenantOnboardingException::alreadyMember();
            }
        });

        TenantRoles::sync($user, $tenant, $role);

        LogService::info('tenancy.invitation_accepted', [
            'event_type' => 'tenancy.invitation_accepted',
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ], 'security');

        return $tenant;
    }

    /** Plazas ocupadas: membresías (cualquier estado) + invitaciones pendientes aún utilizables. */
    private function seats(Tenant $tenant): int
    {
        return (int) DB::table('tenant_user')->where('tenant_id', $tenant->getKey())->count()
            + TenantInvitation::query()->where('tenant_id', $tenant->getKey())->usable()->count();
    }

    private function assertOpen(TenantInvitation $invitation, bool $allowExpired = false): void
    {
        if ($invitation->accepted_at !== null) {
            throw TenantOnboardingException::invitationUsed();
        }

        if ($invitation->revoked_at !== null) {
            throw TenantOnboardingException::invitationRevoked();
        }

        if (! $allowExpired && $invitation->isExpired()) {
            throw TenantOnboardingException::invitationExpired();
        }
    }

    /**
     * Nadie invita, reenvía ni revoca con un rol superior al suyo (misma regla que los miembros).
     */
    private function assertCanGrant(Tenant $tenant, User $actor, string $role): void
    {
        $actorRank = TenantRoles::actorRankFor($tenant, $actor, 'tenant-invitations.manage');

        if (TenantRoles::rank($role) > $actorRank) {
            throw TenantOnboardingException::roleInsufficient();
        }
    }

    private function isMember(Tenant $tenant, string $email): bool
    {
        return DB::table('tenant_user')
            ->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.tenant_id', $tenant->getKey())
            ->whereRaw('lower(users.email) = ?', [$email])
            ->exists();
    }

    private function send(Tenant $tenant, User $inviter, TenantInvitation $invitation, string $token): void
    {
        Notification::route('mail', $invitation->email)->notify(new TenantInvitationNotification(
            $tenant->name,
            $inviter->name,
            $invitation->role,
            $token,
            $invitation->expires_at,
        ));
    }

    private function newToken(): string
    {
        return Str::random(64);
    }

    private function expiry(): CarbonInterface
    {
        return now()->addHours(max(1, (int) config('multi-tenancy.onboarding.invitations.ttl_hours', 168)));
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}

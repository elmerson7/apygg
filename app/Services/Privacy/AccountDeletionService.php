<?php

namespace App\Services\Privacy;

use App\Contracts\AccountDeletionServiceInterface;
use App\Contracts\AuditServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\Privacy\DeletionSecondFactorInterface;
use App\Enums\TenantMembershipStatus;
use App\Exceptions\PrivacyException;
use App\Models\AccountDeletionRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CacheService;
use App\Support\Privacy\PersonalDataRegistry;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Borrado de cuenta: solicitud con contraseña, gracia cancelable y ejecución idempotente por los contribuidores.
 * Respeta «último dueño de un tenant» y «último administrador» (docs/tenant-onboarding.md).
 */
class AccountDeletionService implements AccountDeletionServiceInterface
{
    public function __construct(
        private readonly PersonalDataRegistry $registry,
        private readonly AuditServiceInterface $audit,
        private readonly NotificationServiceInterface $notifications,
        private readonly DeletionSecondFactorInterface $secondFactor,
    ) {}

    public function request(User $user, string $password, ?string $secondFactor, ?string $ip): AccountDeletionRequest
    {
        if (! Hash::check($password, (string) $user->password)) {
            throw PrivacyException::passwordInvalid();
        }

        $this->secondFactor->verify($user, $secondFactor);

        $request = DB::transaction(function () use ($user, $ip): AccountDeletionRequest {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $open = $this->pending($user);
            if ($open instanceof AccountDeletionRequest) {
                return $open;
            }

            $this->assertCanErase($user);

            $created = AccountDeletionRequest::query()->create([
                'user_id' => $user->getKey(),
                'status' => AccountDeletionRequest::PENDING,
                'requested_at' => now(),
                'scheduled_for' => now()->addDays(max(0, (int) config('privacy.deletion.grace_days', 14))),
                'ip_address' => $ip,
            ]);

            $this->audit->recordAction('privacy.erasure-requested', $user, null, ['request_id' => $created->id, 'scheduled_for' => $created->scheduled_for->toIso8601String()]);
            $this->notifications->notify($user, 'account_deletion_scheduled', ['date' => $created->scheduled_for->toDateString()]);

            return $created;
        });

        return $request;
    }

    public function cancel(User $user): AccountDeletionRequest
    {
        return DB::transaction(function () use ($user): AccountDeletionRequest {
            $request = AccountDeletionRequest::query()->where('user_id', $user->getKey())
                ->where('status', AccountDeletionRequest::PENDING)->lockForUpdate()->first();

            if (! $request instanceof AccountDeletionRequest) {
                throw PrivacyException::deletionNotPending();
            }

            $request->forceFill(['status' => AccountDeletionRequest::CANCELLED, 'cancelled_at' => now()])->save();
            $this->audit->recordAction('privacy.erasure-cancelled', $user, null, ['request_id' => $request->id]);

            return $request;
        });
    }

    public function pending(User $user): ?AccountDeletionRequest
    {
        return AccountDeletionRequest::query()->where('user_id', $user->getKey())
            ->whereIn('status', [AccountDeletionRequest::PENDING, AccountDeletionRequest::PROCESSING])->latest('id')->first();
    }

    public function dueIds(): array
    {
        return array_values(AccountDeletionRequest::query()->where('status', AccountDeletionRequest::PENDING)
            ->where('scheduled_for', '<=', now())->orderBy('id')->limit(500)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function execute(int $requestId): void
    {
        $userId = DB::transaction(function () use ($requestId): ?int {
            $request = AccountDeletionRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if (! $request instanceof AccountDeletionRequest
                || $request->status !== AccountDeletionRequest::PENDING
                || $request->scheduled_for->isFuture()) {
                return null;
            }

            $user = User::withTrashed()->whereKey($request->user_id)->lockForUpdate()->first();
            if (! $user instanceof User) {
                $request->forceFill(['status' => AccountDeletionRequest::COMPLETED, 'completed_at' => now()])->save();

                return null;
            }

            // Bloquea los tenants de los que es dueño, en orden de id (sin interbloqueos): un cambio de dueños
            // simultáneo no deja la empresa sin dueño.
            Tenant::query()->whereIn('id', $this->ownedTenantIds($user))->orderBy('id')->lockForUpdate()->get();

            try {
                $this->assertCanErase($user, lock: true);
            } catch (PrivacyException $e) {
                $request->forceFill(['status' => AccountDeletionRequest::FAILED, 'failure_reason' => $e->errorCode()])->save();
                $this->notifications->notify($user, 'account_deletion_blocked', ['reason' => $e->getMessage()]);

                return null;
            }

            $this->audit->recordAction('privacy.erasure-completed', null, null, ['user_id' => $user->getKey(), 'request_id' => $request->id]);

            // `profile` (anonimiza y da de baja la cuenta) siempre al final: los demás aún necesitan la cuenta.
            $contributors = $this->registry->all();
            usort($contributors, fn ($a, $b) => ($a->key() === 'profile') <=> ($b->key() === 'profile'));
            foreach ($contributors as $contributor) {
                $contributor->erase($user);
            }

            $request->forceFill(['status' => AccountDeletionRequest::COMPLETED, 'completed_at' => now(), 'ip_address' => null])->save();

            return (int) $user->getKey();
        });

        if ($userId !== null) {
            $this->afterErase($userId);
        }
    }

    public function markFailed(int $requestId, string $reason): void
    {
        AccountDeletionRequest::query()->whereKey($requestId)->whereIn('status', [AccountDeletionRequest::PENDING, AccountDeletionRequest::PROCESSING])
            ->update(['status' => AccountDeletionRequest::FAILED, 'failure_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
    }

    public function purgeFinished(): int
    {
        $days = (int) config('privacy.retention.deletion_requests_days', 365);

        if ($days <= 0) {
            return 0;
        }

        return AccountDeletionRequest::query()
            ->whereIn('status', [AccountDeletionRequest::COMPLETED, AccountDeletionRequest::CANCELLED, AccountDeletionRequest::FAILED])
            ->where('updated_at', '<', now()->subDays($days))->delete();
    }

    /**
     * @throws PrivacyException `last_owner`, `last_admin`
     */
    private function assertCanErase(User $user, bool $lock = false): void
    {
        $sole = $this->soleOwnerTenantIds($user);
        if ($sole !== []) {
            throw PrivacyException::lastOwner($sole);
        }

        if ($user->hasRole('admin')) {
            $others = User::query()->whereHas('roles', fn ($q) => $q->where('roles.name', 'admin'))
                ->where('users.id', '!=', $user->getKey());

            if ($lock) {
                $others->lockForUpdate();
            }

            if (! $others->exists()) {
                throw PrivacyException::lastAdmin();
            }
        }
    }

    /**
     * @return list<int>
     */
    private function ownedTenantIds(User $user): array
    {
        return array_values(DB::table('tenant_user')->where('user_id', $user->getKey())
            ->where('role', TenantRoles::owner())->where('status', TenantMembershipStatus::Active->value)
            ->pluck('tenant_id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Tenants en los que es el ÚNICO dueño activo.
     *
     * @return list<int>
     */
    private function soleOwnerTenantIds(User $user): array
    {
        return array_values(DB::table('tenant_user as tu')
            ->where('tu.user_id', $user->getKey())
            ->where('tu.role', TenantRoles::owner())
            ->where('tu.status', TenantMembershipStatus::Active->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('tenant_user as other')
                ->whereColumn('other.tenant_id', 'tu.tenant_id')
                ->where('other.user_id', '!=', $user->getKey())
                ->where('other.role', TenantRoles::owner())
                ->where('other.status', TenantMembershipStatus::Active->value))
            ->orderBy('tu.tenant_id')->pluck('tu.tenant_id')->map(fn ($id) => (int) $id)->all());
    }

    private function afterErase(int $userId): void
    {
        try {
            CacheService::forget('user:'.$userId);
            CacheService::forget('user:list');
            // `unsearchable()` lo aporta el módulo `search` (trait Searchable, referido por nombre para que compile sin el módulo); sin él no hay índice que limpiar.
            $erased = User::withTrashed()->find($userId);
            if ($erased !== null && in_array('App\\Traits\\Searchable', class_uses_recursive($erased), true)) {
                call_user_func([$erased, 'unsearchable']);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

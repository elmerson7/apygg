<?php

namespace App\Services;

use App\Contracts\AuditServiceInterface;
use App\Exceptions\AuditException;
use App\Helpers\Actor;
use App\Helpers\Redactor;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Auditoría de negocio (docs/audit.md). Sin estado entre peticiones (Octane): el actor, la IP y el
 * `request_id` se leen de la petición en curso en cada llamada.
 */
class AuditService implements AuditServiceInterface
{
    private const CSV_HEADER = ['id', 'created_at', 'tenant_id', 'event', 'auditable_type', 'auditable_id', 'actor_type', 'actor_id', 'reason', 'ip_address', 'request_id', 'old_values', 'new_values'];

    public function __construct(private readonly TenantContext $tenants) {}

    public function recordModelEvent(Model $model, string $event, ?array $old, ?array $new): ?AuditLog
    {
        if (! config('audit.enabled')) {
            return null;
        }

        return $this->write(
            $event,
            $this->tenantIdFor($model),
            $model->getMorphClass(),
            (string) $model->getKey(),
            $old,
            $new,
            null,
        );
    }

    public function recordAction(string $action, ?Model $subject = null, ?string $reason = null, array $context = [], ?int $tenantId = null): ?AuditLog
    {
        $reason = $this->requireReason($action, $reason);

        if (! config('audit.enabled')) {
            return null;
        }

        return $this->write(
            $action,
            $tenantId ?? ($subject !== null ? $this->tenantIdFor($subject) : $this->contextTenantId()),
            $subject?->getMorphClass(),
            $subject !== null ? (string) $subject->getKey() : null,
            null,
            $context === [] ? null : $context,
            $reason,
        );
    }

    public function requireReason(string $action, ?string $reason): ?string
    {
        $reason = $reason === null ? '' : trim($reason);
        $reason = $reason === '' ? null : mb_substr($reason, 0, (int) config('audit.reason_max_length', 500));

        if ($reason === null && in_array($action, (array) config('audit.reason_required', []), true)) {
            throw AuditException::reasonRequired($action);
        }

        return $reason;
    }

    public function paginateForTenant(Tenant $tenant, User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->tenantQuery($tenant, $actor, $filters)->paginate($perPage);
    }

    public function paginateForPlatform(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filtered(AuditLog::query(), $filters, true)->paginate($perPage);
    }

    public function exportForTenant(Tenant $tenant, User $actor, array $filters): \Generator
    {
        $query = $this->tenantQuery($tenant, $actor, $filters);

        return $this->rows($query);
    }

    public function exportForPlatform(array $filters): \Generator
    {
        return $this->rows($this->filtered(AuditLog::query(), $filters, true));
    }

    public function prune(?int $days = null, bool $dryRun = false): int
    {
        $days ??= (int) config('audit.retention_days', 0);

        if ($days <= 0) {
            return 0;
        }

        $query = AuditLog::query()->where('created_at', '<', now()->subDays($days));

        return $dryRun ? $query->count() : $query->delete();
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function write(string $event, ?int $tenantId, ?string $type, ?string $id, ?array $old, ?array $new, ?string $reason): AuditLog
    {
        [$actorType, $actorId] = $this->actor();
        $request = request();

        return AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'event' => $event,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'old_values' => $old === null ? null : Redactor::redact($old),
            'new_values' => $new === null ? null : Redactor::redact($new),
            'reason' => $reason === null ? null : Redactor::redactString($reason),
            'ip_address' => $request->ip(),
            'request_id' => $this->requestId($request),
        ]);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function actor(): array
    {
        $request = request();

        if ($request->attributes->get('authenticated_via') === 'api-key' && $request->attributes->get('api_key_id')) {
            return ['api_key', (string) $request->attributes->get('api_key_id')];
        }

        $id = Actor::id();

        return $id !== null ? ['user', (string) $id] : ['system', null];
    }

    private function requestId(Request $request): ?string
    {
        $id = $request->headers->get('X-Request-ID');

        return is_string($id) && $id !== '' ? mb_substr($id, 0, 64) : null;
    }

    /**
     * El propio tenant se audita en su tenant; un modelo con `tenant_id` en el suyo; el resto en el activo.
     */
    private function tenantIdFor(Model $model): ?int
    {
        if ($model instanceof Tenant) {
            return (int) $model->getKey();
        }

        $own = $model->getAttribute('tenant_id');

        return is_numeric($own) ? (int) $own : $this->contextTenantId();
    }

    private function contextTenantId(): ?int
    {
        return $this->tenants->enabled() ? $this->tenants->id() : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function tenantQuery(Tenant $tenant, User $actor, array $filters): Builder
    {
        TenantRoles::actorRankFor($tenant, $actor, 'tenant-audit.read');

        // El tenant sale SIEMPRE del contexto: un `tenant_id` en los filtros se ignora.
        return $this->filtered(AuditLog::query()->where('tenant_id', $tenant->getKey()), $filters, false);
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function filtered(Builder $query, array $filters, bool $platform): Builder
    {
        if ($platform && ! empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['actor_type'])) {
            $query->where('actor_type', $filters['actor_type']);
        }
        if (! empty($filters['actor_id'])) {
            $query->where('actor_id', (string) $filters['actor_id']);
        }
        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }
        if (! empty($filters['model'])) {
            $model = (string) $filters['model'];
            $like = '%\\'.addcslashes($model, '\\%_');
            $query->where(fn (Builder $q) => $q->where('auditable_type', $model)->orWhere('auditable_type', 'like', $like));
        }
        if (! empty($filters['model_id'])) {
            $query->where('auditable_id', (string) $filters['model_id']);
        }

        return $query->orderByDesc('id');
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return \Generator<int, list<string>>
     */
    private function rows(Builder $query): \Generator
    {
        yield self::CSV_HEADER;

        $max = (int) config('audit.export.max_rows', 100000);
        $count = 0;

        foreach ($query->lazyById((int) config('audit.export.chunk', 500), 'id')->take($max) as $log) {
            $count++;

            yield [
                (string) $log->id,
                $log->created_at->toIso8601String(),
                (string) $log->tenant_id,
                $log->event,
                (string) $log->auditable_type,
                (string) $log->auditable_id,
                $log->actor_type,
                (string) $log->actor_id,
                (string) $log->reason,
                (string) $log->ip_address,
                (string) $log->request_id,
                $log->old_values === null ? '' : (string) json_encode($log->old_values, JSON_UNESCAPED_UNICODE),
                $log->new_values === null ? '' : (string) json_encode($log->new_values, JSON_UNESCAPED_UNICODE),
            ];
        }
    }
}

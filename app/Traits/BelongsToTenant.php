<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Tenancy\Exceptions\CrossTenantViolation;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;

/**
 * BelongsToTenant
 *
 * Aislamiento fail-closed por tenant (requiere columna `tenant_id`). Solo actúa con
 * `MULTI_TENANCY_ENABLED=true`; ver docs/multi-tenancy.md.
 *
 * - Lectura: scope global; sin tenant en el contexto lanza `MissingTenantContext`.
 * - Creación: rellena `tenant_id` con el del contexto; uno distinto lanza `CrossTenantViolation`.
 * - Cambio de `tenant_id` en un registro existente: siempre `CrossTenantViolation`.
 * - Defensa extra: al hidratar, actualizar, borrar o restaurar se comprueba que el registro es
 *   del tenant activo (cubre `withoutGlobalScopes()` y la restauración de modelos en jobs, que
 *   Laravel hace sin scopes).
 *
 * Escrituras masivas: `TenantEloquentBuilder` filtra `update()`/`delete()` por el scope, prohíbe cambiar
 * `tenant_id` y rellena (o valida) `tenant_id` en `insert`/`insertOrIgnore`/`insertGetId`/`upsert`.
 * `DB::table()` y `toBase()` quedan fuera: los vigila `ArchTest`.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if (! $context->enabled()) {
                return;
            }

            $current = $model->getAttribute('tenant_id');

            if ($context->isWithoutTenant()) {
                // Plataforma: el tenant lo decide quien llama, o el del contexto si lo hay.
                if ($current === null) {
                    $model->setAttribute('tenant_id', $context->id() ?? throw MissingTenantContext::forModel($model::class, 'create'));
                }

                return;
            }

            $expected = $context->requireId($model::class, 'create');

            if ($current === null) {
                $model->setAttribute('tenant_id', $expected);
            } elseif ((int) $current !== $expected) {
                throw CrossTenantViolation::foreignRecord($model::class);
            }
        });

        static::updating(function (Model $model): void {
            self::assertTenantUnchanged($model);
            self::assertBelongsToActiveTenant($model);
        });

        static::deleting(fn (Model $model) => self::assertBelongsToActiveTenant($model));

        static::restoring(fn (Model $model) => self::assertBelongsToActiveTenant($model));

        static::retrieved(fn (Model $model) => self::assertBelongsToActiveTenant($model));
    }

    /**
     * @param  Builder  $query
     * @return TenantEloquentBuilder<static>
     */
    public function newEloquentBuilder($query): TenantEloquentBuilder
    {
        return new TenantEloquentBuilder($query);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Verificar si el modelo pertenece a un tenant específico
     */
    public function belongsToTenant(int|string $tenantId): bool
    {
        return (int) $this->getAttribute('tenant_id') === (int) $tenantId;
    }

    private static function assertTenantUnchanged(Model $model): void
    {
        if (app(TenantContext::class)->enabled() && $model->isDirty('tenant_id')) {
            throw CrossTenantViolation::tenantChanged($model::class);
        }
    }

    private static function assertBelongsToActiveTenant(Model $model): void
    {
        $context = app(TenantContext::class);

        // Con select parcial sin la columna no hay nada que comparar (el scope sigue filtrando).
        if (! $context->enforcing() || ! array_key_exists('tenant_id', $model->getAttributes())) {
            return;
        }

        $expected = $context->requireId($model::class, 'access');

        if ((int) ($model->getOriginal('tenant_id') ?? $model->getAttribute('tenant_id')) !== $expected) {
            throw CrossTenantViolation::foreignRecord($model::class);
        }
    }
}

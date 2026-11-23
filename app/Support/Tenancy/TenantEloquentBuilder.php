<?php

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\CrossTenantViolation;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Builder de los modelos con `BelongsToTenant`: cierra las escrituras masivas que el scope de lectura no cubre.
 *
 * - `update()` / `delete()`: ya van filtrados por el scope global; aquí además se prohíbe tocar `tenant_id`.
 * - `insert`, `insertOrIgnore`, `insertGetId`, `upsert`: rellenan `tenant_id` con el del contexto, o lanzan
 *   `CrossTenantViolation` si una fila trae otro. Dentro de `TenantQuery::withoutTenant()` cada fila debe
 *   traer su `tenant_id` (si falta, `MissingTenantContext`). Con multiempresa apagado no hace nada.
 *
 * `DB::table()` y `toBase()` siguen saltándose todo: lo vigila una regla de `ArchTest`.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class TenantEloquentBuilder extends Builder
{
    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): bool
    {
        return $this->toBase()->insert($this->stampRows($values, 'insert'));
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        return (int) $this->toBase()->insertOrIgnore($this->stampRows($values, 'insertOrIgnore'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insertGetId(array $values, ?string $sequence = null): int|string
    {
        /** @var array<string, mixed> $row */
        $row = $this->stampRows($values, 'insertGetId');

        return $this->toBase()->insertGetId($row, $sequence);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, string>|string  $uniqueBy
     * @param  array<array-key, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        if (app(TenantContext::class)->enabled() && is_array($update) && in_array('tenant_id', $update, true)) {
            throw CrossTenantViolation::tenantChanged($this->getModel()::class);
        }

        return (int) $this->toBase()->upsert($this->stampRows($values, 'upsert'), $uniqueBy, $update);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        if (app(TenantContext::class)->enabled() && array_key_exists('tenant_id', $values)) {
            throw CrossTenantViolation::tenantChanged($this->getModel()::class);
        }

        return parent::update($values);
    }

    /**
     * @param  array<array-key, mixed>  $values  una fila (asociativa) o una lista de filas
     * @return array<array-key, mixed>
     */
    private function stampRows(array $values, string $operation): array
    {
        $context = app(TenantContext::class);

        if (! $context->enabled() || $values === []) {
            return $values;
        }

        $model = $this->getModel()::class;
        $single = ! array_is_list($values) && ! is_array(reset($values));
        $rows = $single ? [$values] : $values;

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $current = $row['tenant_id'] ?? null;

            if ($context->isWithoutTenant()) {
                // Plataforma: cada fila declara su tenant (o lo toma del contexto si lo hay).
                $rows[$i]['tenant_id'] = $current ?? $context->id() ?? throw MissingTenantContext::forModel($model, $operation);

                continue;
            }

            $expected = $context->requireId($model, $operation);

            if ($current === null) {
                $rows[$i]['tenant_id'] = $expected;
            } elseif ((int) $current !== $expected) {
                throw CrossTenantViolation::foreignRecord($model);
            }
        }

        return $single ? $rows[0] : $rows;
    }
}

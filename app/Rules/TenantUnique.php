<?php

namespace App\Rules;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * `unique:` acotado al tenant activo: la unicidad es POR EMPRESA.
 *
 * Un `unique:tabla,col` normal mira todas las empresas: rechaza un valor porque OTRO tenant ya lo usa
 * (oráculo entre tenants y colisiones falsas). Con esta regla dos tenants pueden repetir el mismo valor.
 * Combínala con un índice único `(tenant_id, col)` en la migración. Con multiempresa apagado se
 * comporta como `unique` normal; activo y sin tenant falla cerrado (`MissingTenantContext`).
 *
 * Uso: `'code' => ['required', (new TenantUnique('projects', 'code'))->ignore($project->id)]`.
 */
class TenantUnique implements ValidationRule
{
    private int|string|null $ignoreId = null;

    private string $ignoreColumn = 'id';

    public function __construct(
        private readonly string $table,
        private readonly ?string $column = null,
    ) {}

    /** Ignora una fila (al actualizar). */
    public function ignore(int|string $id, string $idColumn = 'id'): static
    {
        $this->ignoreId = $id;
        $this->ignoreColumn = $idColumn;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $rule = Rule::unique($this->table, $this->column ?? $attribute);
        $context = app(TenantContext::class);

        if ($context->enforcing()) {
            $rule->where('tenant_id', $context->requireId(self::class, 'validate'));
        }

        if ($this->ignoreId !== null) {
            $rule->ignore($this->ignoreId, $this->ignoreColumn);
        }

        if (Validator::make([$attribute => $value], [$attribute => [$rule]])->fails()) {
            $fail('validation.unique')->translate();
        }
    }
}

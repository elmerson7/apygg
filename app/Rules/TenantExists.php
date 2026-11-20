<?php

namespace App\Rules;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * `exists:` acotado al tenant activo.
 *
 * Un `exists:tabla,id` normal sobre una tabla con `tenant_id` consulta TODAS las empresas: responde
 * «existe» para un id de otro tenant y es un oráculo entre tenants (docs/multi-tenancy.md, «Qué NO
 * hacer»). Esta regla añade `where tenant_id = <tenant del contexto>`. Con multiempresa apagado se
 * comporta como `exists` normal; con multiempresa activo y sin tenant en el contexto falla cerrado
 * (`MissingTenantContext`).
 *
 * Uso: `'project_id' => ['required', 'integer', new TenantExists('projects')]`.
 */
class TenantExists implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $rule = Rule::exists($this->table, $this->column);
        $context = app(TenantContext::class);

        if ($context->enforcing()) {
            $rule->where('tenant_id', $context->requireId(self::class, 'validate'));
        }

        if (Validator::make([$attribute => $value], [$attribute => [$rule]])->fails()) {
            $fail('validation.exists')->translate();
        }
    }
}

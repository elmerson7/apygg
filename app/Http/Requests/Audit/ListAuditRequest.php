<?php

namespace App\Http\Requests\Audit;

use App\Http\Requests\BaseFormRequest;

/**
 * Filtros de la auditoría (`/tenant/audit`, `/admin/audit` y sus exportaciones). El tenant de
 * `/tenant/audit` nunca viene de aquí: sale del contexto.
 */
class ListAuditRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'actor_type' => ['sometimes', 'string', 'in:user,api_key,system'],
            'actor_id' => ['sometimes', 'string', 'max:64'],
            'event' => ['sometimes', 'string', 'max:100'],
            'model' => ['sometimes', 'string', 'max:255'],
            'model_id' => ['sometimes', 'string', 'max:64'],
            'tenant_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

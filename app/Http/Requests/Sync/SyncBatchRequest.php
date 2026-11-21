<?php

namespace App\Http\Requests\Sync;

use App\Http\Requests\BaseFormRequest;

/**
 * Cuerpo de `POST /sync/batch` (módulo offline-sync). Permisos y reglas de datos son por recurso y por operación
 * (los aplica el servicio); aquí solo la forma del lote.
 */
class SyncBatchRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $needsKey = (bool) config('offline-sync.require_idempotency_key', true);

        return [
            'operations' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('offline-sync.max_operations', 100))],
            'operations.*' => ['array'],
            'operations.*.idempotency_key' => [$needsKey ? 'required' : 'nullable', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/', 'distinct'],
            'operations.*.resource' => ['required', 'string', 'max:64'],
            'operations.*.op' => ['required', 'string', 'in:create,update,delete'],
            'operations.*.id' => ['nullable', 'string', 'max:64'],
            'operations.*.data' => ['nullable', 'array'],
            'operations.*.if_match' => ['nullable', 'string', 'max:255'],
        ];
    }
}

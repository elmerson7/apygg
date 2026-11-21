<?php

namespace App\Http\Requests\Sync;

use App\Http\Requests\BaseFormRequest;

/**
 * Query de `GET <recurso>?updated_since=...` (módulo offline-sync). La autorización la hace el controller.
 */
class SyncChangesRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'updated_since' => ['nullable', 'string', 'max:64', 'date'],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

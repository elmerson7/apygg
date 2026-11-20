<?php

namespace App\Http\Requests\Files;

use App\Http\Requests\BaseFormRequest;

/**
 * Intención de subida directa: lo que el cliente declara del archivo ANTES de subirlo.
 * El contenido real se verifica al completar.
 */
class StoreFileUploadRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'mime_type' => ['required', 'string', 'max:191'],
            'checksum_sha256' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'category' => ['sometimes', 'string', 'in:'.implode(',', array_keys((array) config('files.storage_paths', [])))],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Files;

use App\Http\Requests\BaseFormRequest;

/**
 * Partes de una subida multipart para las que se piden URLs prefirmadas.
 */
class PresignUploadPartsRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'part_numbers' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('files.multipart.max_urls_per_request', 100))],
            'part_numbers.*' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}

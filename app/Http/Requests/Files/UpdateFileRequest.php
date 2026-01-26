<?php

namespace App\Http\Requests\Files;

use App\Http\Requests\BaseFormRequest;
use App\Models\File;

/**
 * UpdateFileRequest
 *
 * Form Request para validación de actualización de metadatos de archivos.
 */
class UpdateFileRequest extends BaseFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $file = File::find($this->route('id'));

        return $file && ($file->user_id === $this->user()->id || $this->user()->isAdmin());
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
            'category' => [
                'sometimes',
                'string',
                'in:'.implode(',', array_keys(config('files.storage_paths', []))),
            ],
            'is_public' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.max' => 'El nombre no puede exceder 255 caracteres.',
            'description.max' => 'La descripción no puede exceder 1000 caracteres.',
            'category.in' => 'La categoría seleccionada no es válida.',
        ];
    }
}

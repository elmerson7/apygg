<?php

namespace App\Http\Requests\Files;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\File;

/**
 * StoreFileRequest
 *
 * Form Request para validación de upload de archivos.
 */
class StoreFileRequest extends BaseFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Cualquier usuario autenticado puede subir archivos
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $maxSize = config('files.max_sizes.default', 10 * 1024 * 1024); // 10MB por defecto
        $category = $this->input('category', 'default');

        // Obtener límite según categoría
        if ($category !== 'default' && isset(config('files.max_sizes')[$category])) {
            $maxSize = config("files.max_sizes.{$category}");
        }

        // Obtener tipos MIME permitidos según categoría
        $allowedMimes = $this->getAllowedMimesForCategory($category);

        return [
            'file' => [
                'required',
                'file',
                File::types($allowedMimes)
                    ->max($maxSize / 1024), // Laravel File rule espera KB
            ],
            'category' => [
                'sometimes',
                'string',
                'in:'.implode(',', array_keys(config('files.storage_paths', []))),
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
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
            'file.required' => 'El archivo es requerido.',
            'file.file' => 'El campo debe ser un archivo válido.',
            'file.max' => 'El archivo excede el tamaño máximo permitido.',
            'category.in' => 'La categoría seleccionada no es válida.',
            'description.max' => 'La descripción no puede exceder 1000 caracteres.',
        ];
    }

    /**
     * Obtener tipos MIME permitidos según categoría
     */
    protected function getAllowedMimesForCategory(string $category): array
    {
        // Si la categoría tiene tipos específicos, usarlos
        if ($category !== 'default' && isset(config('files.allowed_mimes')[$category])) {
            return config("files.allowed_mimes.{$category}");
        }

        // Si no, determinar por tipo de archivo común
        // Por defecto, permitir imágenes y documentos comunes
        return array_merge(
            config('files.allowed_mimes.image', []),
            config('files.allowed_mimes.document', [])
        );
    }
}

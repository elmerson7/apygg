<?php

namespace App\Http\Requests\Files;

use App\Http\Requests\BaseFormRequest;
use App\Models\File;

class GenerateUploadUrlRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'type' => [
                'required',
                'string',
                'in:' . implode(',', [File::TYPE_AVATAR, File::TYPE_DOCUMENT, File::TYPE_GENERAL, File::TYPE_TEMP])
            ],
            'original_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[^\/\\\:*?"<>|]+$/' // Evitar caracteres problemáticos
            ],
            'mime_type' => [
                'required',
                'string',
                'max:100'
            ],
            'size' => [
                'required',
                'integer',
                'min:1',
                'max:' . $this->getMaxSizeForType()
            ],
            'meta' => [
                'nullable',
                'array'
            ],
            'meta.*' => [
                'nullable',
                'string'
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'El tipo de archivo es obligatorio.',
            'type.in' => 'El tipo de archivo debe ser uno de: avatar, document, general, temp.',
            'original_name.required' => 'El nombre del archivo es obligatorio.',
            'original_name.regex' => 'El nombre del archivo contiene caracteres no válidos.',
            'mime_type.required' => 'El tipo MIME es obligatorio.',
            'size.required' => 'El tamaño del archivo es obligatorio.',
            'size.min' => 'El archivo debe tener al menos 1 byte.',
            'size.max' => 'El archivo excede el tamaño máximo permitido.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Validar MIME type según el tipo de archivo
        if ($this->has('type') && $this->has('mime_type')) {
            $type = $this->input('type');
            $mimeType = $this->input('mime_type');

            if (!File::isValidMimeForType($mimeType, $type)) {
                $this->merge([
                    'mime_type_invalid' => true
                ]);
            }
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->has('mime_type_invalid')) {
                $validator->errors()->add('mime_type', 'El tipo MIME no está permitido para este tipo de archivo.');
            }
        });
    }

    private function getMaxSizeForType(): int
    {
        $type = $this->input('type');
        
        return match ($type) {
            File::TYPE_AVATAR => config('files.max_size_avatar', 2097152), // 2MB
            File::TYPE_DOCUMENT => config('files.max_size_document', 52428800), // 50MB
            default => config('files.max_size', 10485760), // 10MB
        };
    }
}

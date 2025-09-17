<?php

namespace App\Http\Requests\Files;

use App\Http\Requests\BaseFormRequest;
use App\Models\File;

class DirectUploadRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:' . $this->getMaxSizeInKb()
            ],
            'type' => [
                'required',
                'string',
                'in:' . implode(',', [File::TYPE_AVATAR, File::TYPE_DOCUMENT, File::TYPE_GENERAL, File::TYPE_TEMP])
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
            'file.required' => 'El archivo es obligatorio.',
            'file.file' => 'Debe ser un archivo válido.',
            'file.max' => 'El archivo excede el tamaño máximo permitido.',
            'type.required' => 'El tipo de archivo es obligatorio.',
            'type.in' => 'El tipo de archivo debe ser uno de: avatar, document, general, temp.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Validar MIME type del archivo subido
        if ($this->hasFile('file') && $this->has('type')) {
            $file = $this->file('file');
            $type = $this->input('type');
            $mimeType = $file->getMimeType();

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
                $validator->errors()->add('file', 'El tipo de archivo no está permitido para esta categoría.');
            }
        });
    }

    private function getMaxSizeInKb(): int
    {
        $type = $this->input('type');
        
        $maxBytes = match ($type) {
            File::TYPE_AVATAR => config('files.max_size_avatar', 2097152), // 2MB
            File::TYPE_DOCUMENT => config('files.max_size_document', 52428800), // 50MB
            default => config('files.max_size', 10485760), // 10MB
        };

        return (int) ($maxBytes / 1024); // Convertir a KB para la regla de validación
    }
}

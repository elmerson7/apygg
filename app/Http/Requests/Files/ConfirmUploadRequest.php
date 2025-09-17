<?php

namespace App\Http\Requests\Files;

use App\Http\Requests\BaseFormRequest;
use App\Models\File;

class ConfirmUploadRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        // Verificar que el usuario puede confirmar este archivo
        if (!auth()->check()) {
            return false;
        }

        $fileId = $this->route('file_id') ?? $this->input('file_id');
        
        if (!$fileId) {
            return false;
        }

        $file = File::find($fileId);
        
        return $file && 
               $file->user_id === auth()->id() && 
               $file->status === File::STATUS_UPLOADING;
    }

    public function rules(): array
    {
        return [
            'file_id' => [
                'required',
                'string',
                'size:26', // ULID length
                'exists:files,id'
            ],
            'checksum' => [
                'nullable',
                'string',
                'size:64', // SHA256 length
                'regex:/^[a-f0-9]{64}$/i'
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'file_id.required' => 'El ID del archivo es obligatorio.',
            'file_id.size' => 'El ID del archivo debe tener 26 caracteres.',
            'file_id.exists' => 'El archivo especificado no existe.',
            'checksum.size' => 'El checksum debe tener 64 caracteres.',
            'checksum.regex' => 'El checksum debe ser un hash SHA256 válido.',
        ];
    }
}

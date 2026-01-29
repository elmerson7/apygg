<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\BaseFormRequest;

/**
 * StoreSettingsRequest
 *
 * Form Request para validación de creación de Settings.
 */
class StoreSettingsRequest extends BaseFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:255', 'unique:settings,key', 'regex:/^[a-zA-Z0-9._]+$/'],
            'value' => ['required'],
            'type' => ['required', 'string', 'in:string,integer,boolean,json,array'],
            'group' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
            'is_encrypted' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('type');
            $value = $this->input('value');

            if ($type && $value !== null) {
                $isValid = match ($type) {
                    'integer' => is_numeric($value),
                    'boolean' => is_bool($value) || in_array(strtolower((string) $value), ['true', 'false', '1', '0', 'yes', 'no']),
                    'json' => is_string($value) && json_decode($value) !== null && json_last_error() === JSON_ERROR_NONE,
                    'array' => is_array($value) || (is_string($value) && json_decode($value) !== null && is_array(json_decode($value, true))),
                    'string' => is_string($value),
                    default => false,
                };

                if (! $isValid) {
                    $validator->errors()->add('value', "El valor no coincide con el tipo '{$type}' especificado");
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     */
    protected function getCustomMessages(): array
    {
        return [
            'key.required' => 'La clave es requerida',
            'key.string' => 'La clave debe ser texto',
            'key.max' => 'La clave no puede exceder 255 caracteres',
            'key.unique' => 'La clave ya existe',
            'key.regex' => 'La clave solo puede contener letras, números, puntos y guiones bajos',
            'value.required' => 'El valor es requerido',
            'type.required' => 'El tipo es requerido',
            'type.in' => 'El tipo debe ser uno de: string, integer, boolean, json, array',
            'group.string' => 'El grupo debe ser texto',
            'group.max' => 'El grupo no puede exceder 100 caracteres',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede exceder 1000 caracteres',
            'is_public.boolean' => 'is_public debe ser verdadero o falso',
            'is_encrypted.boolean' => 'is_encrypted debe ser verdadero o falso',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    protected function getCustomAttributes(): array
    {
        return [
            'key' => 'clave',
            'value' => 'valor',
            'type' => 'tipo',
            'group' => 'grupo',
            'description' => 'descripción',
            'is_public' => 'público',
            'is_encrypted' => 'encriptado',
        ];
    }
}

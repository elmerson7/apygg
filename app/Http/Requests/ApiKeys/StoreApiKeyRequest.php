<?php

namespace App\Http\Requests\ApiKeys;

use App\Http\Requests\BaseFormRequest;

/**
 * StoreApiKeyRequest
 *
 * Form Request para validación de creación de API Keys.
 */
class StoreApiKeyRequest extends BaseFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'scopes' => ['sometimes', 'nullable', 'array'],
            'scopes.*' => ['required', 'string', 'regex:/^([a-z0-9_-]+\.[a-z0-9_-]+|\*)$/'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'environment' => ['sometimes', 'nullable', 'string', 'in:live,test'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    protected function getCustomMessages(): array
    {
        return [
            'name.required' => 'El nombre es requerido',
            'name.string' => 'El nombre debe ser texto',
            'name.min' => 'El nombre debe tener al menos 2 caracteres',
            'name.max' => 'El nombre no puede exceder 255 caracteres',
            'scopes.array' => 'Los scopes deben ser un array',
            'scopes.*.required' => 'Cada scope es requerido',
            'scopes.*.string' => 'Cada scope debe ser texto',
            'scopes.*.regex' => 'Cada scope debe tener el formato "resource.action" o ser "*"',
            'expires_at.date' => 'La fecha de expiración debe ser una fecha válida',
            'expires_at.after' => 'La fecha de expiración debe ser posterior a la fecha actual',
            'environment.in' => 'El entorno debe ser "live" o "test"',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    protected function getCustomAttributes(): array
    {
        return [
            'name' => 'nombre',
            'scopes' => 'scopes',
            'scopes.*' => 'scope',
            'expires_at' => 'fecha de expiración',
            'environment' => 'entorno',
        ];
    }
}

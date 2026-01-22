<?php

namespace App\Core\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Base Request
 * 
 * Clase base para todos los Form Requests de la aplicación.
 * Proporciona validaciones comunes y sanitización automática.
 */
abstract class BaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    abstract public function rules(): array;

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->sanitizeInput();
    }

    /**
     * Sanitizar inputs automáticamente
     */
    protected function sanitizeInput(): void
    {
        $input = $this->all();

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                // Trim espacios en blanco
                $input[$key] = trim($value);
                
                // Convertir strings vacíos a null
                if ($input[$key] === '') {
                    $input[$key] = null;
                }
            }
        }

        $this->merge($input);
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge($this->getCommonMessages(), $this->getCustomMessages());
    }

    /**
     * Mensajes de error comunes en español
     */
    protected function getCommonMessages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'email' => 'El campo :attribute debe ser un correo electrónico válido.',
            'unique' => 'El valor del campo :attribute ya está en uso.',
            'exists' => 'El valor seleccionado para :attribute no es válido.',
            'min' => 'El campo :attribute debe tener al menos :min caracteres.',
            'max' => 'El campo :attribute no puede tener más de :max caracteres.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'string' => 'El campo :attribute debe ser una cadena de texto.',
            'array' => 'El campo :attribute debe ser un array.',
            'date' => 'El campo :attribute debe ser una fecha válida.',
            'date_format' => 'El campo :attribute debe tener el formato :format.',
            'uuid' => 'El campo :attribute debe ser un UUID válido.',
            'confirmed' => 'La confirmación del campo :attribute no coincide.',
            'password' => 'La contraseña es incorrecta.',
        ];
    }

    /**
     * Mensajes personalizados (sobrescribir en clases hijas)
     */
    protected function getCustomMessages(): array
    {
        return [];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return array_merge($this->getCommonAttributes(), $this->getCustomAttributes());
    }

    /**
     * Atributos comunes en español
     */
    protected function getCommonAttributes(): array
    {
        return [
            'email' => 'correo electrónico',
            'password' => 'contraseña',
            'name' => 'nombre',
            'created_at' => 'fecha de creación',
            'updated_at' => 'fecha de actualización',
        ];
    }

    /**
     * Atributos personalizados (sobrescribir en clases hijas)
     */
    protected function getCustomAttributes(): array
    {
        return [];
    }

    /**
     * Regla de validación para UUID
     */
    protected function uuidRule(bool $required = true): array
    {
        $rules = ['uuid'];

        if ($required) {
            array_unshift($rules, 'required');
        } else {
            array_unshift($rules, 'nullable');
        }

        return $rules;
    }

    /**
     * Regla de validación para UUID que debe existir en una tabla
     */
    protected function uuidExistsRule(string $table, string $column = 'id', bool $required = true): array
    {
        $rules = ['uuid', Rule::exists($table, $column)];

        if ($required) {
            array_unshift($rules, 'required');
        } else {
            array_unshift($rules, 'nullable');
        }

        return $rules;
    }

    /**
     * Regla de validación para email
     */
    protected function emailRule(bool $required = true, bool $unique = false, ?string $table = null, ?string $column = 'email', $ignore = null): array
    {
        $rules = ['email'];

        if ($unique && $table) {
            $rule = Rule::unique($table, $column);
            if ($ignore) {
                $rule->ignore($ignore);
            }
            $rules[] = $rule;
        }

        if ($required) {
            array_unshift($rules, 'required');
        } else {
            array_unshift($rules, 'nullable');
        }

        return $rules;
    }

    /**
     * Regla de validación para fecha
     */
    protected function dateRule(bool $required = true, string $format = 'Y-m-d'): array
    {
        $rules = ['date'];

        if ($format !== 'Y-m-d') {
            $rules[] = "date_format:{$format}";
        }

        if ($required) {
            array_unshift($rules, 'required');
        } else {
            array_unshift($rules, 'nullable');
        }

        return $rules;
    }

    /**
     * Regla de validación para fecha ISO 8601
     */
    protected function dateIsoRule(bool $required = true): array
    {
        return $this->dateRule($required, 'Y-m-d\TH:i:s\Z');
    }

    /**
     * Regla de validación para contraseña fuerte
     */
    protected function strongPasswordRule(bool $required = true): array
    {
        $rules = ['string', 'min:8'];

        if ($required) {
            array_unshift($rules, 'required');
        } else {
            array_unshift($rules, 'nullable');
        }

        return $rules;
    }
}

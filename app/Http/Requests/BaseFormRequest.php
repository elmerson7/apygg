<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Base Form Request
 *
 * Clase base para todos los Form Requests de la aplicación.
 * Proporciona validaciones y mensajes comunes. El recorte de espacios y la
 * conversión de cadenas vacías a null los hace el framework (TrimStrings y
 * ConvertEmptyStringsToNull); aquí no se modifica la entrada.
 */
abstract class BaseFormRequest extends FormRequest
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
     *
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge($this->getCommonMessages(), $this->getCustomMessages());
    }

    /**
     * Mensajes comunes: salen de `lang/<idioma>/validation.php` (idioma resuelto por petición).
     *
     * @return array<string, mixed>
     */
    protected function getCommonMessages(): array
    {
        return [];
    }

    /**
     * Mensajes personalizados (sobrescribir en clases hijas)
     *
     * @return array<string, mixed>
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
     * Nombres de atributo comunes: salen de `lang/<idioma>/validation.php` (`attributes`).
     *
     * @return array<string, mixed>
     */
    protected function getCommonAttributes(): array
    {
        return [];
    }

    /**
     * Atributos personalizados (sobrescribir en clases hijas)
     *
     * @return array<string, mixed>
     */
    protected function getCustomAttributes(): array
    {
        return [];
    }

    /**
     * Regla de validación para UUID
     *
     * @return list<string>
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
     *
     * @return list<Exists|string>
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
     *
     * @param  int|string|null  $ignore  ID a ignorar en la regla de unicidad
     * @return list<Unique|string>
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
     *
     * @return list<string>
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
     *
     * @return list<string>
     */
    protected function dateIsoRule(bool $required = true): array
    {
        return $this->dateRule($required, 'Y-m-d\TH:i:s\Z');
    }

    /**
     * Regla de validación para contraseña fuerte
     *
     * @return list<string>
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

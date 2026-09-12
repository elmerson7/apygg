<?php

namespace App\Http\Requests\Llm;

use App\Http\Requests\BaseFormRequest;

class StoreLlmAreaRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/', 'unique:llm_areas,key'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'primary_provider' => ['required', 'string', 'in:openai,openrouter,deepseek'],
            'primary_model' => ['nullable', 'string', 'max:255'],
            'fallbacks' => ['nullable', 'array', 'max:5'],
            'fallbacks.*.provider' => ['required_with:fallbacks', 'string', 'in:openai,openrouter,deepseek'],
            'fallbacks.*.model' => ['required_with:fallbacks', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.required' => 'La clave del área es obligatoria',
            'key.regex' => 'La clave solo admite minúsculas, números, guiones y guiones bajos',
            'key.unique' => 'Ya existe un área con esa clave',
            'primary_provider.in' => 'El proveedor indicado no está soportado',
            'fallbacks.max' => 'No se admiten más de 5 modelos de respaldo',
        ];
    }
}

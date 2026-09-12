<?php

namespace App\Http\Requests\Llm;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateLlmAreaRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'key' => [
                'sometimes', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('llm_areas', 'key')->ignore($this->route('llmArea')),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'primary_provider' => ['sometimes', 'string', 'in:openai,openrouter,deepseek'],
            'primary_model' => ['nullable', 'string', 'max:255'],
            'fallbacks' => ['nullable', 'array', 'max:5'],
            'fallbacks.*.provider' => ['required_with:fallbacks', 'string', 'in:openai,openrouter,deepseek'],
            'fallbacks.*.model' => ['required_with:fallbacks', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}

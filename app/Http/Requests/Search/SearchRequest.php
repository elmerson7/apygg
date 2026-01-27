<?php

namespace App\Http\Requests\Search;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * SearchRequest
 *
 * Form Request para validación de búsqueda global.
 * Valida parámetros de búsqueda, modelos y filtros.
 */
class SearchRequest extends BaseFormRequest
{
    /**
     * Modelos disponibles para búsqueda
     */
    protected array $availableModels = [
        'users' => \App\Models\User::class,
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // La búsqueda está disponible para usuarios autenticados
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'models' => ['sometimes', 'nullable', 'array'],
            'models.*' => [
                'required',
                'string',
                Rule::in(array_keys($this->availableModels)),
            ],
            'filters' => ['sometimes', 'nullable', 'array'],
            'filters.*' => ['sometimes', 'array'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    protected function getCustomMessages(): array
    {
        return [
            'q.required' => 'El término de búsqueda es requerido',
            'q.string' => 'El término de búsqueda debe ser texto',
            'q.min' => 'El término de búsqueda debe tener al menos 2 caracteres',
            'q.max' => 'El término de búsqueda no puede exceder 255 caracteres',
            'models.array' => 'Los modelos deben ser un array',
            'models.*.required' => 'Cada modelo es requerido',
            'models.*.string' => 'Cada modelo debe ser texto',
            'models.*.in' => 'Uno o más modelos no son válidos',
            'filters.array' => 'Los filtros deben ser un array',
            'per_page.integer' => 'El número de resultados por página debe ser un número',
            'per_page.min' => 'El número de resultados por página debe ser al menos 1',
            'per_page.max' => 'El número de resultados por página no puede exceder 100',
            'page.integer' => 'El número de página debe ser un número',
            'page.min' => 'El número de página debe ser al menos 1',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    protected function getCustomAttributes(): array
    {
        return [
            'q' => 'término de búsqueda',
            'models' => 'modelos',
            'models.*' => 'modelo',
            'filters' => 'filtros',
            'per_page' => 'resultados por página',
            'page' => 'página',
        ];
    }

    /**
     * Obtener modelos disponibles
     */
    public function getAvailableModels(): array
    {
        return $this->availableModels;
    }

    /**
     * Obtener modelos a buscar (o todos si no se especifican)
     */
    public function getModelsToSearch(): array
    {
        $requestedModels = $this->input('models', []);

        if (empty($requestedModels)) {
            return $this->availableModels;
        }

        $models = [];
        foreach ($requestedModels as $modelKey) {
            if (isset($this->availableModels[$modelKey])) {
                $models[$modelKey] = $this->availableModels[$modelKey];
            }
        }

        return $models;
    }
}

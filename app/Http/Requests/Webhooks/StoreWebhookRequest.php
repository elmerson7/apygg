<?php

namespace App\Http\Requests\Webhooks;

use App\Http\Requests\BaseFormRequest;
use App\Models\Webhook;
use Illuminate\Validation\Rule;

/**
 * StoreWebhookRequest
 *
 * Form Request para validación de creación de webhooks.
 */
class StoreWebhookRequest extends BaseFormRequest
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
        $availableEvents = Webhook::getAvailableEvents();

        return [
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['sometimes', 'nullable', 'array'],
            'events.*' => ['required', 'string', Rule::in($availableEvents)],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'paused'])],
            'timeout' => ['sometimes', 'integer', 'min:5', 'max:300'],
            'max_retries' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'secret' => ['sometimes', 'nullable', 'string', 'min:32', 'max:255'],
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
            'url.required' => 'La URL es requerida',
            'url.url' => 'La URL debe ser válida',
            'url.max' => 'La URL no puede exceder 2048 caracteres',
            'events.array' => 'Los eventos deben ser un array',
            'events.*.required' => 'Cada evento es requerido',
            'events.*.string' => 'Cada evento debe ser texto',
            'events.*.in' => 'El evento no es válido',
            'status.in' => 'El estado debe ser: active, inactive o paused',
            'timeout.integer' => 'El timeout debe ser un número entero',
            'timeout.min' => 'El timeout debe ser al menos 5 segundos',
            'timeout.max' => 'El timeout no puede exceder 300 segundos',
            'max_retries.integer' => 'El máximo de reintentos debe ser un número entero',
            'max_retries.min' => 'El máximo de reintentos debe ser al menos 1',
            'max_retries.max' => 'El máximo de reintentos no puede exceder 10',
            'secret.string' => 'El secret debe ser texto',
            'secret.min' => 'El secret debe tener al menos 32 caracteres',
            'secret.max' => 'El secret no puede exceder 255 caracteres',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    protected function getCustomAttributes(): array
    {
        return [
            'name' => 'nombre',
            'url' => 'URL',
            'events' => 'eventos',
            'events.*' => 'evento',
            'status' => 'estado',
            'timeout' => 'timeout',
            'max_retries' => 'máximo de reintentos',
            'secret' => 'secret',
        ];
    }
}

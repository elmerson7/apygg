<?php

namespace App\Http\Requests\Logs;

use App\Http\Requests\BaseFormRequest;

/**
 * Filtros de los listados de logs (/admin/logs/*).
 *
 * Valida los parámetros antes de llegar a la consulta: una fecha mal
 * formada o un código de estado no numérico responden 422, no 500.
 * El permiso system.logs lo exige la ruta.
 */
class ListLogsRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'trace_id' => ['nullable', 'string', 'max:64'], // = request_id de la respuesta: sigue una petición por los tres logs
            // activity
            'action' => ['nullable', 'string', 'max:100'],
            'model_type' => ['nullable', 'string', 'max:255'],
            // api
            'method' => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS,get,post,put,patch,delete,head,options'],
            'status_code' => ['nullable', 'integer', 'between:100,599'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            // security
            'event_type' => ['nullable', 'string', 'max:100'],
            'ip_address' => ['nullable', 'ip'],
        ];
    }
}

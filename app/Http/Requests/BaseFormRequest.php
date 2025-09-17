<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

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
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        $errors = $validator->errors()->toArray();
        
        // Log the validation error to api_problem_details
        \App\Services\Logging\ApiProblemLogger::logValidationError($errors, $this);
        
        // Formato RFC 7807 Problem Details consistente con tu middleware
        $problem = [
            'success' => false,
            'type' => 'https://damblix.dev/errors/ValidationException',
            'title' => 'Validation failed',
            'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'detail' => 'The given data was invalid.',
            'instance' => $this->fullUrl(),
            'errors' => $errors,
            'meta' => [
                'trace_id' => $this->attributes->get('trace_id'),
                'timestamp' => now()->toISOString(),
                'version' => '1.0',
            ],
        ];

        throw new HttpResponseException(
            response()->json($problem, Response::HTTP_UNPROCESSABLE_ENTITY, [
                'Content-Type' => 'application/problem+json'
            ])
        );
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'email' => 'El campo :attribute debe ser un email válido.',
            'string' => 'El campo :attribute debe ser una cadena de texto.',
            'min' => 'El campo :attribute debe tener al menos :min caracteres.',
            'max' => 'El campo :attribute no puede tener más de :max caracteres.',
            'unique' => 'El :attribute ya está en uso.',
            'confirmed' => 'La confirmación de :attribute no coincide.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'email' => 'correo electrónico',
            'password' => 'contraseña',
            'password_confirmation' => 'confirmación de contraseña',
            'name' => 'nombre',
        ];
    }
}

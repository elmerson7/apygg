<?php

namespace App\Exceptions;

use Exception;

/**
 * BusinessLogicException
 * 
 * Excepción para errores de lógica de negocio.
 * Generalmente retorna código HTTP 422 (Unprocessable Entity).
 * 
 * @package App\Exceptions
 */
class BusinessLogicException extends ApiException
{
    /**
     * Código de error de negocio
     */
    protected ?string $businessCode;

    /**
     * Constructor
     *
     * @param string $message Mensaje de error
     * @param string|null $businessCode Código de error de negocio (opcional)
     * @param array $extensions Campos adicionales
     * @param \Throwable|null $previous Excepción anterior
     */
    public function __construct(
        string $message = 'Error de lógica de negocio',
        ?string $businessCode = null,
        array $extensions = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            422, // Unprocessable Entity
            [],
            'business_logic_error',
            null,
            array_merge($extensions, $businessCode ? ['business_code' => $businessCode] : []),
            $previous
        );

        $this->businessCode = $businessCode;
    }

    /**
     * Obtener código de error de negocio
     */
    public function getBusinessCode(): ?string
    {
        return $this->businessCode;
    }

    /**
     * Crear excepción de regla de negocio violada
     */
    public static function ruleViolation(string $message, ?string $businessCode = null): self
    {
        return new self($message, $businessCode, ['violation_type' => 'business_rule']);
    }

    /**
     * Crear excepción de estado inválido
     */
    public static function invalidState(string $message, ?string $currentState = null, ?string $expectedState = null): self
    {
        $extensions = [];
        if ($currentState) {
            $extensions['current_state'] = $currentState;
        }
        if ($expectedState) {
            $extensions['expected_state'] = $expectedState;
        }

        return new self($message, 'INVALID_STATE', $extensions);
    }

    /**
     * Crear excepción de recurso no disponible
     */
    public static function resourceUnavailable(string $message, ?string $resourceType = null): self
    {
        $extensions = [];
        if ($resourceType) {
            $extensions['resource_type'] = $resourceType;
        }

        return new self($message, 'RESOURCE_UNAVAILABLE', $extensions);
    }

    /**
     * Crear excepción de operación no permitida
     */
    public static function operationNotAllowed(string $message, ?string $operation = null): self
    {
        $extensions = [];
        if ($operation) {
            $extensions['operation'] = $operation;
        }

        return new self($message, 'OPERATION_NOT_ALLOWED', $extensions);
    }
}

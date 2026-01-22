<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * ApiException
 * 
 * Excepción base para errores de API con código HTTP específico.
 * 
 * @package App\Exceptions
 */
class ApiException extends Exception
{
    /**
     * Código HTTP de la respuesta
     */
    protected int $statusCode;

    /**
     * Errores adicionales (para validación)
     */
    protected array $errors;

    /**
     * Tipo de error (RFC 7807)
     */
    protected ?string $type;

    /**
     * Instancia específica del error (RFC 7807)
     */
    protected ?string $instance;

    /**
     * Campos adicionales (RFC 7807)
     */
    protected array $extensions;

    /**
     * Constructor
     *
     * @param string $message Mensaje de error
     * @param int $statusCode Código HTTP (default: 400)
     * @param array $errors Errores adicionales (opcional)
     * @param string|null $type Tipo de error (RFC 7807)
     * @param string|null $instance Instancia específica del error
     * @param array $extensions Campos adicionales
     * @param \Throwable|null $previous Excepción anterior
     */
    public function __construct(
        string $message = 'Error en la API',
        int $statusCode = 400,
        array $errors = [],
        ?string $type = null,
        ?string $instance = null,
        array $extensions = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        
        $this->statusCode = $statusCode;
        $this->errors = $errors;
        $this->type = $type;
        $this->instance = $instance;
        $this->extensions = $extensions;
    }

    /**
     * Obtener código HTTP
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Obtener errores adicionales
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Obtener tipo de error
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Obtener instancia del error
     */
    public function getInstance(): ?string
    {
        return $this->instance;
    }

    /**
     * Obtener extensiones
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Crear excepción 400 Bad Request
     */
    public static function badRequest(string $message = 'Solicitud inválida', array $errors = []): self
    {
        return new self($message, 400, $errors, 'bad_request');
    }

    /**
     * Crear excepción 401 Unauthorized
     */
    public static function unauthorized(string $message = 'No autenticado'): self
    {
        return new self($message, 401, [], 'unauthorized');
    }

    /**
     * Crear excepción 403 Forbidden
     */
    public static function forbidden(string $message = 'No autorizado'): self
    {
        return new self($message, 403, [], 'forbidden');
    }

    /**
     * Crear excepción 404 Not Found
     */
    public static function notFound(string $message = 'Recurso no encontrado'): self
    {
        return new self($message, 404, [], 'not_found');
    }

    /**
     * Crear excepción 409 Conflict
     */
    public static function conflict(string $message = 'Conflicto con el estado actual', array $extensions = []): self
    {
        return new self($message, 409, [], 'conflict', null, $extensions);
    }

    /**
     * Crear excepción 422 Unprocessable Entity (Validación)
     */
    public static function validation(string $message = 'Error de validación', array $errors = []): self
    {
        return new self($message, 422, $errors, 'validation_error');
    }

    /**
     * Crear excepción 429 Too Many Requests
     */
    public static function tooManyRequests(string $message = 'Demasiadas solicitudes', ?int $retryAfter = null): self
    {
        $extensions = [];
        if ($retryAfter !== null) {
            $extensions['retry_after'] = $retryAfter;
        }

        return new self($message, 429, [], 'rate_limit_exceeded', null, $extensions);
    }

    /**
     * Crear excepción 500 Internal Server Error
     */
    public static function internalServerError(string $message = 'Error interno del servidor'): self
    {
        return new self($message, 500, [], 'internal_server_error');
    }

    /**
     * Crear excepción 503 Service Unavailable
     */
    public static function serviceUnavailable(string $message = 'Servicio no disponible', ?int $retryAfter = null): self
    {
        $extensions = [];
        if ($retryAfter !== null) {
            $extensions['retry_after'] = $retryAfter;
        }

        return new self($message, 503, [], 'service_unavailable', null, $extensions);
    }
}

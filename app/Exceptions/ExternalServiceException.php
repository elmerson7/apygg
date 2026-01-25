<?php

namespace App\Exceptions;

/**
 * ExternalServiceException
 *
 * Excepción para errores de servicios externos (APIs, webhooks, etc.).
 * Generalmente retorna código HTTP 502 (Bad Gateway) o 503 (Service Unavailable).
 */
class ExternalServiceException extends ApiException
{
    /**
     * Nombre del servicio externo
     */
    protected ?string $serviceName;

    /**
     * Código de error del servicio externo
     */
    protected ?string $serviceErrorCode;

    /**
     * URL del servicio que falló
     */
    protected ?string $serviceUrl;

    /**
     * Constructor
     *
     * @param  string  $message  Mensaje de error
     * @param  string|null  $serviceName  Nombre del servicio externo
     * @param  int  $statusCode  Código HTTP (default: 502)
     * @param  string|null  $serviceErrorCode  Código de error del servicio
     * @param  string|null  $serviceUrl  URL del servicio
     * @param  array  $extensions  Campos adicionales
     * @param  \Throwable|null  $previous  Excepción anterior
     */
    public function __construct(
        string $message = 'Error en servicio externo',
        ?string $serviceName = null,
        int $statusCode = 502,
        ?string $serviceErrorCode = null,
        ?string $serviceUrl = null,
        array $extensions = [],
        ?\Throwable $previous = null
    ) {
        $extensions = array_merge($extensions, [
            'service_name' => $serviceName,
            'service_error_code' => $serviceErrorCode,
            'service_url' => $serviceUrl,
        ]);

        parent::__construct(
            $message,
            $statusCode,
            [],
            'external_service_error',
            null,
            $extensions,
            $previous
        );

        $this->serviceName = $serviceName;
        $this->serviceErrorCode = $serviceErrorCode;
        $this->serviceUrl = $serviceUrl;
    }

    /**
     * Obtener nombre del servicio
     */
    public function getServiceName(): ?string
    {
        return $this->serviceName;
    }

    /**
     * Obtener código de error del servicio
     */
    public function getServiceErrorCode(): ?string
    {
        return $this->serviceErrorCode;
    }

    /**
     * Obtener URL del servicio
     */
    public function getServiceUrl(): ?string
    {
        return $this->serviceUrl;
    }

    /**
     * Crear excepción de servicio no disponible
     */
    public static function serviceUnavailable(
        string $serviceName,
        ?string $serviceUrl = null,
        ?string $serviceErrorCode = null
    ): self {
        return new self(
            "El servicio {$serviceName} no está disponible",
            $serviceName,
            503,
            $serviceErrorCode,
            $serviceUrl
        );
    }

    /**
     * Crear excepción de timeout
     */
    public static function timeout(
        string $serviceName,
        ?string $serviceUrl = null,
        ?int $timeoutSeconds = null
    ): self {
        $extensions = [];
        if ($timeoutSeconds !== null) {
            $extensions['timeout_seconds'] = $timeoutSeconds;
        }

        return new self(
            "Timeout al conectar con {$serviceName}",
            $serviceName,
            504, // Gateway Timeout
            'TIMEOUT',
            $serviceUrl,
            $extensions
        );
    }

    /**
     * Crear excepción de respuesta inválida
     */
    public static function invalidResponse(
        string $serviceName,
        ?string $serviceUrl = null,
        ?string $serviceErrorCode = null
    ): self {
        return new self(
            "Respuesta inválida del servicio {$serviceName}",
            $serviceName,
            502,
            $serviceErrorCode,
            $serviceUrl
        );
    }

    /**
     * Crear excepción de autenticación fallida
     */
    public static function authenticationFailed(
        string $serviceName,
        ?string $serviceUrl = null
    ): self {
        return new self(
            "Error de autenticación con {$serviceName}",
            $serviceName,
            502,
            'AUTH_FAILED',
            $serviceUrl
        );
    }

    /**
     * Crear excepción de rate limit del servicio externo
     */
    public static function rateLimited(
        string $serviceName,
        ?string $serviceUrl = null,
        ?int $retryAfter = null
    ): self {
        $extensions = [];
        if ($retryAfter !== null) {
            $extensions['retry_after'] = $retryAfter;
        }

        return new self(
            "Rate limit excedido en {$serviceName}",
            $serviceName,
            429,
            'RATE_LIMITED',
            $serviceUrl,
            $extensions
        );
    }
}

<?php

namespace App\Integrations\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base de los errores de una integración externa (docs/integrations.md §4).
 *
 * El código de negocio no captura `ConnectionException` ni excepciones de un SDK: la capa de
 * integración las traduce a uno de estos cinco tipos, y `RetryPolicy` decide por TIPO si
 * reintenta. Nunca pongas secretos ni datos personales en el mensaje: acaba en logs.
 */
abstract class IntegrationException extends RuntimeException
{
    public function __construct(string $message = '', private readonly ?string $service = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Servicio que falló (`sms`, `payments`...), si se conoce. */
    public function service(): ?string
    {
        return $this->service;
    }

    /** ¿Tiene sentido repetir la misma llamada? Solo los transitorios y el rate limit. */
    abstract public function isRetryable(): bool;
}

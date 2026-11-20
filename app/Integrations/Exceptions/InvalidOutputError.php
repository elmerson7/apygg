<?php

namespace App\Integrations\Exceptions;

/** El proveedor respondió con éxito pero con un cuerpo inesperado: no se reintenta, se registra. */
class InvalidOutputError extends IntegrationException
{
    public function isRetryable(): bool
    {
        return false;
    }
}

<?php

namespace App\Support\Sequences;

use App\Exceptions\ApiException;

/**
 * La secuencia llegó a su valor máximo (`max_value`): no se reparten más números.
 * Excepción de dominio: 409 con `code` = `sequence_exhausted` (se resuelve subiendo el máximo).
 */
final class SequenceExhausted extends ApiException
{
    public function __construct(string $message = 'La secuencia llegó a su máximo.')
    {
        parent::__construct($message, 409);
    }

    public function errorCode(): string
    {
        return 'sequence_exhausted';
    }
}

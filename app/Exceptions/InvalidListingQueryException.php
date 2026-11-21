<?php

namespace App\Exceptions;

/**
 * Filtro, orden o `include` fuera de la lista blanca del listado (o con un valor mal formado): 400 `invalid_query`.
 * `errors` indica qué parámetro falló.
 */
class InvalidListingQueryException extends ApiException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message, 400, $errors);
    }

    public function errorCode(): string
    {
        return 'invalid_query';
    }
}

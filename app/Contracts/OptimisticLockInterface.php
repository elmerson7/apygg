<?php

namespace App\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;

interface OptimisticLockInterface
{
    /** ETag opaco (entre comillas) del estado actual de la fila, calculado sobre los atributos leídos de la BD. */
    public function etag(Model $model): string;

    /**
     * Ejecuta `$apply` con la fila bloqueada (`FOR UPDATE`) tras comprobar `If-Match` contra su versión real.
     * Sin cabecera: 428 si `$required`, si no pasa. No coincide: 412 `precondition_failed`.
     *
     * @param  Closure(Model): mixed  $apply  recibe la fila fresca y bloqueada
     */
    public function guard(Model $model, ?string $ifMatch, bool $required, Closure $apply, bool $withTrashed = false): mixed;
}

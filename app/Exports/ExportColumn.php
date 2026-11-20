<?php

namespace App\Exports;

use Closure;

/**
 * Columna de un exportador: clave del atributo, clave de traducción de la cabecera y formato.
 * `format`: text | int | decimal | bool | date | datetime. `value` opcional calcula la celda desde el modelo.
 */
final class ExportColumn
{
    /**
     * @param  Closure(mixed): mixed|null  $value
     */
    public function __construct(
        public readonly string $key,
        public readonly string $header,
        public readonly string $format = 'text',
        public readonly ?Closure $value = null,
    ) {}
}

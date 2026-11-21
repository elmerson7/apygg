<?php

namespace App\Support\Listing;

use Attribute;

/**
 * Declara qué admite un listado: filtros (`filter[campo]=`), orden (`sort=-campo,otro`) e `include=`.
 *
 * Es la ÚNICA fuente de verdad del endpoint: la lee `ListingQuery` en ejecución (lista blanca, 400
 * `invalid_query` si algo no está) y la extensión de OpenAPI para documentar los parámetros.
 * Se pone sobre el método del controller que lista (ver docs/api-routes-conventions.md, «Filtros, orden e include»).
 *
 * `filters`: `nombre => 'tipo[:columna]'`; sin columna, la columna es el nombre. Tipos:
 *  - `exact`    igualdad; con comas (`a,b`) → `IN`.
 *  - `int`      como `exact`, pero solo enteros (otro valor → 400).
 *  - `bool`     `1|0|true|false`.
 *  - `partial`  subcadena sin distinguir mayúsculas (`ILIKE`, con `%` `_` escapados).
 *  - `from` / `to`  fecha ISO 8601, `>=` / `<=` sobre la columna.
 *  - `custom`   lo aplica el controller (callback en `$custom`) o el servicio que recibe el `ListingQuery`.
 * `sorts`: lista de campos ordenables (`['created_at', 'name']`) o `nombre => columna`.
 * `includes`: relaciones que el cliente puede pedir (se cargan con `with`).
 * `legacy`: nombres de filtro que además se aceptan sueltos (`?role=x` = `filter[role]=x`); `sort_by` y
 *  `sort_order` / `order` se aceptan siempre como alias de `sort` y su dirección. Así un listado que ya
 *  existía no rompe a sus clientes.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Listing
{
    /**
     * @param  array<string, string>  $filters
     * @param  list<string>|array<string, string>  $sorts
     * @param  list<string>  $includes
     * @param  list<string>  $legacy
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly array $sorts = [],
        public readonly array $includes = [],
        public readonly string $defaultSort = '-created_at',
        public readonly array $legacy = [],
        public readonly int $defaultPerPage = 15,
        public readonly int $maxPerPage = 100,
    ) {}

    /**
     * @return array<string, string> campo público => columna
     */
    public function sortColumns(): array
    {
        $columns = [];
        foreach ($this->sorts as $key => $value) {
            $columns[is_int($key) ? $value : $key] = $value;
        }

        return $columns;
    }

    /**
     * @return array{0: string, 1: string} [tipo, columna] del filtro
     */
    public function filterDefinition(string $name): array
    {
        [$type, $column] = array_pad(explode(':', $this->filters[$name], 2), 2, null);

        return [$type, $column ?? $name];
    }
}

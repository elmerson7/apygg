<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Listing\Listing;
use App\Support\Listing\ListingQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ReflectionMethod;

/**
 * Listados con filtros, orden e include estándar (docs/api-routes-conventions.md, «Filtros, orden e include»).
 *
 * El método del controller declara `#[Listing(...)]` (lista blanca, y de ahí sale la documentación OpenAPI) y llama
 * a `listing($request, $query)` con la consulta ya acotada (tenant, permisos del usuario). Con `?cursor=` o
 * `?pagination=cursor` responde por cursor (sin `COUNT`); si no, por páginas. Siempre paginación SQL.
 */
trait ListsResources
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, callable(Builder<TModel>, string): mixed>  $custom
     */
    protected function listing(Request $request, Builder $query, array $custom = [], ?Listing $spec = null): JsonResponse
    {
        $spec ??= $this->listingSpec($request);

        ListingQuery::fromRequest($request, $spec)->apply($query, $custom);

        $perPage = $this->perPage($request, $spec->defaultPerPage, $spec->maxPerPage);

        return $request->query->has('cursor') || $request->query('pagination') === 'cursor'
            ? $this->sendCursorPaginated($query->cursorPaginate($perPage)->withQueryString())
            : $this->sendPaginated($query->paginate($perPage)->withQueryString());
    }

    /**
     * Los parámetros ya validados de la petición, para quien arma la consulta por su cuenta (p. ej. un servicio).
     */
    protected function listingQuery(Request $request): ListingQuery
    {
        return ListingQuery::fromRequest($request, $this->listingSpec($request));
    }

    private function listingSpec(Request $request): Listing
    {
        $method = $request->route()?->getActionMethod() ?? throw new \LogicException('listing() necesita una ruta.');
        $attributes = (new ReflectionMethod($this, $method))->getAttributes(Listing::class);

        return ($attributes[0] ?? throw new \LogicException(static::class."::{$method}() necesita #[Listing(...)]."))->newInstance();
    }
}

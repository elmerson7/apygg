<?php

namespace App\Support\Listing;

use App\Exceptions\InvalidListingQueryException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

/**
 * Parámetros de un listado ya validados contra su `Listing`: `filter[campo]`, `sort=-campo,otro` e `include=rel`.
 *
 * Inmutable y sin estado compartido (se crea por petición). `fromRequest()` lanza 400 `invalid_query`
 * ante cualquier filtro, orden o include que no esté en la lista blanca; nunca toca la BD.
 * `apply()` lo traslada a la consulta (con desempate por clave primaria para que offset y cursor sean estables).
 */
final class ListingQuery
{
    private const MAX_SORTS = 5;

    /**
     * @param  array<string, string>  $filters  nombre => valor (texto crudo ya validado por tipo)
     * @param  list<array{0: string, 1: string}>  $sorts  [campo público, 'asc'|'desc']
     * @param  list<string>  $includes
     */
    private function __construct(
        public readonly Listing $spec,
        public readonly array $filters,
        public readonly array $sorts,
        public readonly array $includes,
    ) {}

    public static function fromRequest(Request $request, Listing $spec): self
    {
        return new self(
            $spec,
            self::parseFilters($request, $spec),
            self::parseSorts($request, $spec),
            self::parseIncludes($request, $spec),
        );
    }

    public function filter(string $name): ?string
    {
        return $this->filters[$name] ?? null;
    }

    /**
     * Aplica filtros, orden e includes a la consulta.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, callable(Builder<TModel>, string): mixed>  $custom  callbacks de los filtros `custom`
     * @param  list<string>  $handled  filtros `custom` que aplica otro (p. ej. un servicio) y se omiten aquí
     * @return Builder<TModel>
     */
    public function apply(Builder $query, array $custom = [], array $handled = []): Builder
    {
        foreach ($this->filters as $name => $value) {
            [$type, $column] = $this->spec->filterDefinition($name);

            match ($type) {
                'exact', 'int' => $this->applyExact($query, $column, $value),
                'bool' => $query->where($column, filter_var($value, FILTER_VALIDATE_BOOLEAN)),
                'partial' => $query->where($column, 'ILIKE', '%'.addcslashes($value, '%_\\').'%'),
                'from' => $query->where($column, '>=', Carbon::parse($value)),
                'to' => $query->where($column, '<=', Carbon::parse($value)),
                'custom' => $this->applyCustom($query, $name, $value, $custom, $handled),
                default => throw new \LogicException("Tipo de filtro desconocido: {$type}"),
            };
        }

        $this->applySorts($query);

        if ($this->includes !== []) {
            $query->with($this->includes);
        }

        return $query;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applySorts(Builder $query): void
    {
        $columns = $this->spec->sortColumns();
        $direction = 'asc';
        $key = $query->getModel()->getKeyName();
        $tiebreak = true;

        foreach ($this->sorts as [$field, $direction]) {
            $direction = $direction === 'desc' ? 'desc' : 'asc';
            $query->orderBy($columns[$field], $direction);
            $tiebreak = $tiebreak && $columns[$field] !== $key;
        }

        if ($tiebreak) {
            $query->orderBy($key, $direction);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyExact(Builder $query, string $column, string $value): void
    {
        $values = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));

        count($values) > 1 ? $query->whereIn($column, $values) : $query->where($column, $values[0] ?? $value);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, callable>  $custom
     * @param  list<string>  $handled
     */
    private function applyCustom(Builder $query, string $name, string $value, array $custom, array $handled): void
    {
        if (isset($custom[$name])) {
            $custom[$name]($query, $value);

            return;
        }

        if (! in_array($name, $handled, true)) {
            throw new \LogicException("El filtro custom «{$name}» no tiene callback: pásalo en \$custom o márcalo como handled.");
        }
    }

    /**
     * @return array<string, string>
     */
    private static function parseFilters(Request $request, Listing $spec): array
    {
        $raw = $request->query('filter', []);

        if (! is_array($raw)) {
            throw new InvalidListingQueryException('El parámetro filter debe tener la forma filter[campo]=valor.', ['filter' => ['Formato inválido.']]);
        }

        $unknown = array_diff(array_keys($raw), array_keys($spec->filters));
        if ($unknown !== []) {
            $errors = [];
            foreach ($unknown as $name) {
                $errors['filter.'.$name] = ['Filtro no permitido. Permitidos: '.implode(', ', array_keys($spec->filters)).'.'];
            }

            throw new InvalidListingQueryException('Filtro no permitido: '.implode(', ', array_map('strval', $unknown)).'.', $errors);
        }

        // Alias sueltos de listados anteriores (`?role=x`): solo si no vino `filter[role]`.
        foreach ($spec->legacy as $name) {
            $value = $request->query($name);
            if (! array_key_exists($name, $raw) && is_scalar($value) && isset($spec->filters[$name])) {
                $raw[$name] = $value;
            }
        }

        $filters = [];
        foreach ($raw as $name => $value) {
            if (! is_scalar($value)) {
                throw new InvalidListingQueryException("El filtro {$name} debe ser un valor simple.", ['filter.'.$name => ['Valor inválido.']]);
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            self::validateValue((string) $name, $spec->filterDefinition((string) $name)[0], $value);
            $filters[(string) $name] = $value;
        }

        return $filters;
    }

    private static function validateValue(string $name, string $type, string $value): void
    {
        $valid = match ($type) {
            'int' => collect(explode(',', $value))->every(fn ($v) => preg_match('/^-?\d{1,18}$/', trim($v)) === 1),
            'bool' => in_array(strtolower($value), ['1', '0', 'true', 'false'], true),
            'from', 'to' => self::isDate($value),
            default => true,
        };

        if (! $valid) {
            throw new InvalidListingQueryException("Valor inválido para el filtro {$name}.", ['filter.'.$name => ['Valor inválido.']]);
        }
    }

    private static function isDate(string $value): bool
    {
        try {
            Carbon::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function parseSorts(Request $request, Listing $spec): array
    {
        $columns = $spec->sortColumns();
        $raw = $request->query('sort', $request->query('sort_by'));

        if ($raw !== null && ! is_string($raw)) {
            throw new InvalidListingQueryException('El parámetro sort debe ser una lista separada por comas.', ['sort' => ['Formato inválido.']]);
        }

        $explicit = $raw !== null && trim($raw) !== '';
        $legacyOrder = strtolower((string) $request->query('order', $request->query('sort_order', '')));
        $legacyOrder = in_array($legacyOrder, ['asc', 'desc'], true) ? $legacyOrder : null;

        $parts = array_values(array_filter(array_map('trim', explode(',', $explicit ? (string) $raw : $spec->defaultSort)), fn ($p) => $p !== ''));

        if (count($parts) > self::MAX_SORTS) {
            throw new InvalidListingQueryException('Demasiados criterios de orden (máximo '.self::MAX_SORTS.').', ['sort' => ['Demasiados criterios.']]);
        }

        $sorts = [];
        foreach ($parts as $part) {
            $prefixed = str_starts_with($part, '-') || str_starts_with($part, '+');
            $field = ltrim($part, '-+');
            $direction = str_starts_with($part, '-') ? 'desc' : 'asc';

            if (! isset($columns[$field])) {
                throw new InvalidListingQueryException(
                    "Orden no permitido: {$field}. Permitidos: ".implode(', ', array_keys($columns)).'.',
                    ['sort' => ["No se puede ordenar por {$field}."]]
                );
            }

            // `?order=asc|desc` (listados anteriores) solo vale con un único criterio sin signo propio.
            if ($legacyOrder !== null && count($parts) === 1 && (! $prefixed || ! $explicit)) {
                $direction = $legacyOrder;
            }

            $sorts[] = [$field, $direction];
        }

        return $sorts;
    }

    /**
     * @return list<string>
     */
    private static function parseIncludes(Request $request, Listing $spec): array
    {
        $raw = $request->query('include');

        if ($raw === null || $raw === '') {
            return [];
        }

        if (! is_string($raw)) {
            throw new InvalidListingQueryException('El parámetro include debe ser una lista separada por comas.', ['include' => ['Formato inválido.']]);
        }

        $includes = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), fn ($i) => $i !== '')));
        $unknown = array_diff($includes, $spec->includes);

        if ($unknown !== []) {
            throw new InvalidListingQueryException(
                'Include no permitido: '.implode(', ', $unknown).'. Permitidos: '.($spec->includes === [] ? 'ninguno' : implode(', ', $spec->includes)).'.',
                ['include' => ['Relación no permitida.']]
            );
        }

        return $includes;
    }
}

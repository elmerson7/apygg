<?php

namespace App\Http\Resources\Search;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SearchResource
 *
 * Resource para transformar resultados de búsqueda global.
 * Agrupa resultados por tipo de modelo y transforma cada resultado.
 */
class SearchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'query' => $this->resource['query'] ?? '',
            'total_results' => $this->resource['total_results'] ?? 0,
            'results' => $this->resource['results'] ?? [],
            'meta' => $this->resource['meta'] ?? [],
        ];
    }

    /**
     * Crear resource desde resultados de búsqueda
     */
    public static function fromSearchResults(
        string $query,
        array $results,
        array $meta = []
    ): self {
        $totalResults = 0;
        foreach ($results as $modelResults) {
            $totalResults += $modelResults['total'] ?? 0;
        }

        return new self([
            'query' => $query,
            'total_results' => $totalResults,
            'results' => $results,
            'meta' => array_merge([
                'timestamp' => now()->toIso8601String(),
            ], $meta),
        ]);
    }
}

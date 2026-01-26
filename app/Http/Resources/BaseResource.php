<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base Resource
 *
 * Clase base para todos los Resources de la aplicación.
 * Proporciona formato estándar de respuestas y manejo de relaciones.
 */
abstract class BaseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Las clases hijas deben implementar este método.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Las clases hijas deben sobrescribir este método
        return [];
    }

    /**
     * Campos que siempre se incluyen
     */
    protected function getBaseFields(): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->when($this->created_at, $this->created_at?->toIso8601String()),
            'updated_at' => $this->when($this->updated_at, $this->updated_at?->toIso8601String()),
        ];
    }

    /**
     * Helper para incluir campo solo si la relación está cargada
     * Usa el método whenLoaded() del padre de JsonResource
     */
    protected function includeWhenLoaded(string $relation, $value = null)
    {
        if ($value === null) {
            $value = $this->whenLoaded($relation);
        }

        return $this->when($this->resource && $this->resource->relationLoaded($relation), $value);
    }

    /**
     * Incluir campo solo si existe
     */
    protected function whenExists(string $attribute, $value = null)
    {
        if ($value === null) {
            $value = $this->{$attribute} ?? null;
        }

        return $this->when(isset($this->{$attribute}), $value);
    }

    /**
     * Incluir campo solo si no es null
     */
    protected function whenNotNull($value, $default = null)
    {
        return $this->when($value !== null, $value ?? $default);
    }

    /**
     * Formatear fecha a ISO 8601
     */
    protected function formatDate($date): ?string
    {
        if ($date === null) {
            return null;
        }

        if (is_string($date)) {
            $date = \Carbon\Carbon::parse($date);
        }

        return $date->toIso8601String();
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @return array<string, mixed>
     */
    public function with($request): array
    {
        return [
            'meta' => $this->getMeta(),
        ];
    }

    /**
     * Obtener metadatos adicionales
     */
    protected function getMeta(): array
    {
        $meta = [
            'timestamp' => now()->toIso8601String(),
        ];

        // Agregar trace_id si está disponible
        if (request()->hasHeader('X-Trace-ID')) {
            $meta['trace_id'] = request()->header('X-Trace-ID');
        }

        return $meta;
    }

    /**
     * Incluir timestamps si están disponibles
     */
    protected function includeTimestamps(): array
    {
        return [
            'created_at' => $this->formatDate($this->created_at),
            'updated_at' => $this->formatDate($this->updated_at),
        ];
    }

    /**
     * Incluir soft delete timestamp si está disponible
     */
    protected function includeDeletedAt(): array
    {
        return [
            'deleted_at' => $this->when(
                isset($this->deleted_at),
                $this->formatDate($this->deleted_at)
            ),
        ];
    }
}

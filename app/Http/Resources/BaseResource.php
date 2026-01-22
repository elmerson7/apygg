<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base Resource
 * 
 * Clase base para todos los Resources de la aplicación.
 * Proporciona funcionalidad común para transformación de datos.
 */
abstract class BaseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray($request): array;

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @return array<string, mixed>
     */
    public function with($request): array
    {
        return [];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class BaseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Override this method in child classes.
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => parent::toArray($request),
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
            'meta' => [
                'trace_id' => $request->attributes->get('trace_id'),
                'timestamp' => now()->toISOString(),
                'version' => '1.0',
            ],
        ];
    }

    /**
     * Customize the response for a request.
     */
    public function withResponse(Request $request, $response): void
    {
        // Agregar headers consistentes
        $response->header('Content-Type', 'application/json');
        
        // Agregar Cache-Control por defecto (datos privados, no cachear)
        if (!$response->headers->has('Cache-Control')) {
            $response->header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
        }
        
        // Agregar Vary headers para caching
        $existing = $response->headers->get('Vary');
        $vary = array_filter(array_unique(array_merge(
            $existing ? array_map('trim', explode(',', $existing)) : [],
            ['Accept', 'Accept-Language', 'Authorization']
        )));
        
        if ($vary) {
            $response->header('Vary', implode(', ', $vary));
        }
    }

    /**
     * Helper para formatear fechas consistentemente
     */
    protected function formatDate($date): ?string
    {
        return $date?->toISOString();
    }

    /**
     * Helper para incluir datos solo si el usuario es el propietario
     */
    protected function whenOwner(Request $request, $userId, array $data): array
    {
        return $this->when(
            $request->user()?->id === $userId,
            $data
        );
    }

    /**
     * Helper para incluir datos solo si el usuario está autenticado
     */
    protected function whenAuthenticated(Request $request, array $data): array
    {
        return $this->when(
            $request->user() !== null,
            $data
        );
    }
}

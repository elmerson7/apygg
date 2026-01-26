<?php

namespace App\Http\Resources\Files;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * FileResource
 *
 * Resource para transformación de datos de archivos.
 */
class FileResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $file = $this->resource;

        return array_merge($this->getBaseFields(), [
            'name' => $file->name,
            'filename' => $file->filename,
            'path' => $file->path,
            'url' => $file->getUrl(),
            'disk' => $file->disk,
            'mime_type' => $file->mime_type,
            'extension' => $file->extension,
            'size' => $file->size,
            'formatted_size' => $file->formatted_size,
            'type' => $file->type,
            'category' => $file->category,
            'description' => $file->description,
            'metadata' => $file->metadata,
            'is_public' => $file->is_public,
            'expires_at' => $this->formatDate($file->expires_at),
            'is_expired' => $file->isExpired(),

            // Relación con usuario (solo si se carga)
            'user' => $this->whenLoaded('user', function () use ($file) {
                return [
                    'id' => $file->user->id,
                    'name' => $file->user->name,
                    'email' => $file->user->email,
                ];
            }),

            // Timestamps
            'created_at' => $this->formatDate($file->created_at),
            'updated_at' => $this->formatDate($file->updated_at),
        ]);
    }
}

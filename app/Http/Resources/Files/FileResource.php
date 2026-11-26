<?php

namespace App\Http\Resources\Files;

use App\Http\Resources\BaseResource;
use App\Models\File;
use Illuminate\Http\Request;

/**
 * FileResource
 *
 * Resource para transformación de datos de archivos.
 *
 * @mixin File
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
            // Los archivos privados no exponen ruta ni URL: se bajan por download_url
            // Ni ruta ni URL mientras el antivirus no lo dé por bueno (cuarentena)
            'path' => $this->when($file->is_public && $file->isAvailable(), $file->path),
            'url' => $file->getUrl(),
            'download_url' => route('files.download', ['id' => $file->id]),
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
            // Escaneo antivirus: skipped (sin escaneo), pending, clean, infected o failed. Solo skipped/clean se descargan.
            'scan_status' => $file->scan_status->value,
            'scanned_at' => $this->formatDate($file->scanned_at),

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

<?php

namespace App\Http\Resources\ExportImport;

use App\Contracts\ExportServiceInterface;
use App\Http\Resources\BaseResource;
use App\Models\DataExport;
use Illuminate\Http\Request;

/**
 * @mixin DataExport
 */
class DataExportResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DataExport $export */
        $export = $this->resource;

        return [
            'id' => $export->id,
            'exporter' => $export->exporter,
            'format' => $export->format,
            'filters' => $export->filters,
            'status' => $export->status,
            'total_rows' => $export->total_rows,
            'size' => $export->size,
            'filename' => $export->filename,
            'error' => $export->error,
            // Solo si el archivo está listo; caduca en `export.url_minutes`.
            'download_url' => $export->isCompleted() ? app(ExportServiceInterface::class)->temporaryUrl($export) : null,
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'created_at' => $export->created_at?->toIso8601String(),
        ];
    }
}

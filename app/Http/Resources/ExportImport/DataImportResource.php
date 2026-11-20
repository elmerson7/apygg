<?php

namespace App\Http\Resources\ExportImport;

use App\Http\Resources\BaseResource;
use App\Models\DataImport;
use Illuminate\Http\Request;

/**
 * @mixin DataImport
 */
class DataImportResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DataImport $import */
        $import = $this->resource;

        return [
            'id' => $import->id,
            'importer' => $import->importer,
            'status' => $import->status,
            'original_name' => $import->original_name,
            'total_rows' => $import->total_rows,
            'valid_rows' => $import->valid_rows,
            'invalid_rows' => $import->invalid_rows,
            'created_rows' => $import->created_rows,
            'skipped_rows' => $import->skipped_rows,
            'preview' => $import->preview,
            'has_errors_report' => $import->errors_path !== null,
            'error' => $import->error,
            'confirmed_at' => $import->confirmed_at?->toIso8601String(),
            'completed_at' => $import->completed_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }
}

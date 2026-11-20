<?php

namespace App\Contracts;

use App\Exceptions\ExportImport\ExportImportException;
use App\Models\DataImport;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importación de CSV/XLSX con vista previa y confirmación (docs/export-import.md).
 */
interface ImportServiceInterface
{
    /** Guarda el archivo y encola la validación (vista previa). */
    public function upload(User $user, string $importerKey, UploadedFile $file): DataImport;

    /** Valida todas las filas sin guardar nada y deja la vista previa (lo llama el job). */
    public function preview(DataImport $import): void;

    /**
     * Confirma una importación previsualizada y encola el proceso. Solo una vez.
     *
     * @throws ExportImportException
     */
    public function confirm(User $user, int $id): DataImport;

    /** Guarda las filas válidas por lotes (lo llama el job). */
    public function process(DataImport $import): void;

    /**
     * @return LengthAwarePaginator<int, DataImport>
     */
    public function paginateFor(User $user, int $perPage): LengthAwarePaginator;

    public function findFor(User $user, int $id): DataImport;

    /** Informe de errores (CSV) de la importación. */
    public function errorsReport(DataImport $import): StreamedResponse;

    public function markFailed(DataImport $import, string $message): void;

    /** Borra importaciones antiguas con sus archivos. Devuelve cuántas. */
    public function prune(): int;

    /** Pasa a `failed` las importaciones atascadas (worker muerto) más de `export.stuck_minutes`. Devuelve cuántas. */
    public function failStuck(): int;
}

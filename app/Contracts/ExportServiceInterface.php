<?php

namespace App\Contracts;

use App\Models\DataExport;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación a CSV/XLSX en streaming (docs/export-import.md).
 */
interface ExportServiceInterface
{
    /**
     * Valida y encola una exportación del tenant activo (null = plataforma).
     *
     * @param  array<string, mixed>  $filters
     */
    public function request(User $user, string $exporterKey, string $format, array $filters = []): DataExport;

    /**
     * Genera el archivo (lo llama el job). Idempotente: una exportación ya reclamada no se repite.
     */
    public function run(DataExport $export): void;

    /**
     * Exportaciones del usuario en el tenant activo.
     *
     * @return LengthAwarePaginator<int, DataExport>
     */
    public function paginateFor(User $user, int $perPage): LengthAwarePaginator;

    /** @throws ModelNotFoundException si no es suya o es de otro tenant */
    public function findFor(User $user, int $id): DataExport;

    /** URL temporal de descarga (null si no está lista). */
    public function temporaryUrl(DataExport $export): ?string;

    public function download(DataExport $export): StreamedResponse;

    /** Marca como fallida (la usa el job al agotar intentos). */
    public function markFailed(DataExport $export, string $message): void;

    /** Borra exportaciones caducadas con su archivo. Devuelve cuántas. */
    public function prune(): int;

    /** Pasa a `failed` las exportaciones atascadas (worker muerto) más de `export.stuck_minutes`. Devuelve cuántas. */
    public function failStuck(): int;
}

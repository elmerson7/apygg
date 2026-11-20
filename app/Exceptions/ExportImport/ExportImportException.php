<?php

namespace App\Exceptions\ExportImport;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;

/**
 * Errores de dominio de exportar/importar. El `code` es estable (docs/export-import.md).
 */
class ExportImportException extends ApiException
{
    public function __construct(string $message, int $status, private readonly string $stableCode)
    {
        parent::__construct($message, $status, [], $stableCode);
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->stableCode;
    }

    public static function unknownExporter(string $key): self
    {
        return new self("El exportador '{$key}' no existe.", 404, 'exporter_unknown');
    }

    public static function unknownImporter(string $key): self
    {
        return new self("El importador '{$key}' no existe.", 404, 'importer_unknown');
    }

    public static function tenantRequired(): self
    {
        return new self('Esta operación necesita una empresa activa.', 400, 'tenant_required');
    }

    public static function notReady(): self
    {
        return new self('La exportación aún no está lista.', 409, 'export_not_ready');
    }

    public static function fileMissing(): self
    {
        return new self('El archivo ya no está disponible.', 404, 'file_missing');
    }

    public static function notPreviewed(string $status): self
    {
        return new self("La importación no se puede confirmar en estado '{$status}'.", 409, 'import_not_confirmable');
    }

    public static function nothingToImport(): self
    {
        return new self('No hay filas válidas que importar.', 422, 'import_nothing_to_import');
    }

    public static function invalidFile(string $detail): self
    {
        return new self($detail, 422, 'import_file_invalid');
    }
}

<?php

namespace App\Contracts;

/**
 * DocsIndexerInterface
 *
 * Contrato del indexador de documentación.
 */
interface DocsIndexerInterface
{
    /**
     * Reindexar todas las fuentes configuradas.
     *
     * @return array<string, int>
     */
    public function reindex(bool $force = false): array;

    /**
     * Indexar un fichero. Si su contenido no cambió, no se toca.
     *
     * @return array{chunks: int, skipped: bool}
     */
    public function indexFile(string $absolutePath, string $scope, bool $force = false): array;
}

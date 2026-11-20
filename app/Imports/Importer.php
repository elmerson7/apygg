<?php

namespace App\Imports;

/**
 * Importador declarativo (docs/export-import.md): columnas, reglas por fila y cómo se guarda un lote.
 * Debe ser IDEMPOTENTE por clave natural: repetir el archivo no duplica nada (las filas que ya existen
 * se cuentan como `skipped`). Se registra en `config/export.php`.
 */
abstract class Importer
{
    abstract public function key(): string;

    abstract public function permission(): string;

    /**
     * Columnas obligatorias del archivo (cabeceras en minúsculas).
     *
     * @return list<string>
     */
    abstract public function requiredHeaders(): array;

    /**
     * Columnas opcionales reconocidas. Cualquier otra columna del archivo se ignora.
     *
     * @return list<string>
     */
    public function optionalHeaders(): array
    {
        return [];
    }

    /**
     * Reglas de validación de Laravel por columna.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    public function requiresTenant(): bool
    {
        return false;
    }

    /**
     * Guarda un lote de filas YA validadas. `$tenantId` es el tenant del import (null = plataforma).
     *
     * @param  list<array{row: int, data: array<string, mixed>}>  $rows
     * @return array{created: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    abstract public function process(array $rows, ?int $tenantId): array;
}

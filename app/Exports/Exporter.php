<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Exportador declarativo: qué consulta, qué columnas y con qué formato (docs/export-import.md).
 * Se registra en `config/export.php`. Sin estado: se resuelve del contenedor en cada uso.
 */
abstract class Exporter
{
    abstract public function key(): string;

    /** Permiso que exige exportar (lo comprueba la ruta y, de nuevo, el servicio). */
    abstract public function permission(): string;

    /**
     * @return list<ExportColumn>
     */
    abstract public function columns(): array;

    /**
     * Consulta de filas. `$tenantId` es el tenant activo (null = plataforma o multiempresa apagada).
     * Un exportador por tenant DEBE filtrar por él.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<*>
     */
    abstract public function query(array $filters, ?int $tenantId): Builder;

    public function requiresTenant(): bool
    {
        return false;
    }

    /**
     * Reglas de validación de los filtros admitidos.
     *
     * @return array<string, mixed>
     */
    public function filterRules(): array
    {
        return [];
    }

    /** Columna con la que se pagina por lotes (`lazyById`). */
    public function idColumn(): string
    {
        return 'id';
    }

    public function idAlias(): string
    {
        return 'id';
    }

    /**
     * Fila de valores para una entidad, según las columnas.
     *
     * @return list<mixed>
     */
    public function row(Model $model): array
    {
        return array_map(function (ExportColumn $column) use ($model): mixed {
            $raw = $column->value !== null ? ($column->value)($model) : $model->getAttribute($column->key);

            return match ($column->format) {
                'int' => $raw === null ? null : (int) $raw,
                'decimal' => $raw === null ? null : (float) $raw,
                'bool' => $raw === null ? null : (bool) $raw,
                'date' => $raw === null ? null : ($raw instanceof \DateTimeInterface ? $raw->format('Y-m-d') : substr((string) $raw, 0, 10)),
                'datetime' => $raw === null ? null : ($raw instanceof \DateTimeInterface ? $raw->format('Y-m-d H:i:s') : (string) $raw),
                default => $raw,
            };
        }, $this->columns());
    }
}

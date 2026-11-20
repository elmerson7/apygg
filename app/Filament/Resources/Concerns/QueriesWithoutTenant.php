<?php

namespace App\Filament\Resources\Concerns;

use App\Support\Tenancy\TenantQuery;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Tabla (relation manager o página) sobre un modelo `BelongsToTenant` que la plataforma lee de UN
 * tenant concreto (el de la ficha). El back-office no tiene tenant en el contexto: la lectura va con
 * `TenantQuery::withoutTenant()`, que deja rastro en el log de seguridad. Nunca para escribir.
 */
trait QueriesWithoutTenant
{
    /**
     * @return Collection<array-key, mixed>|Paginator<array-key, mixed>|CursorPaginator<array-key, mixed>
     */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        return TenantQuery::withoutTenant(
            fn () => parent::getTableRecords(),
            'backoffice: ficha de empresa',
        );
    }

    public function getAllTableRecordsCount(): int
    {
        return TenantQuery::withoutTenant(fn (): int => parent::getAllTableRecordsCount(), 'backoffice: ficha de empresa');
    }

    /**
     * @return Model|array<string, mixed>|null
     */
    protected function resolveTableRecord(?string $key): Model|array|null
    {
        return TenantQuery::withoutTenant(fn () => parent::resolveTableRecord($key), 'backoffice: ficha de empresa');
    }
}

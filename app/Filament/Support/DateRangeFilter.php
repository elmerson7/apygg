<?php

namespace App\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/** Filtro "desde / hasta" sobre una columna de fecha (por defecto `created_at`). */
final class DateRangeFilter
{
    public static function make(string $column = 'created_at', string $label = 'Fecha'): Filter
    {
        return Filter::make($column)
            ->label($label)
            ->schema([
                DatePicker::make('from')->label('Desde'),
                DatePicker::make('until')->label('Hasta'),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate($column, '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate($column, '<=', $date)));
    }
}

<?php

namespace App\Filament\Resources\Logs\ApiLogs;

use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Resources\Logs\ApiLogs\Pages\ListApiLogs;
use App\Filament\Support\DateRangeFilter;
use App\Filament\Support\Pretty;
use App\Models\Logs\ApiLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Log de peticiones a la API. Solo lectura; permiso `system.logs`. */
class ApiLogResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = ApiLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Registros';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'logs/api';

    protected static ?string $modelLabel = 'registro de API';

    protected static ?string $pluralModelLabel = 'API';

    protected static ?string $navigationLabel = 'API';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name,email'))
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
                TextColumn::make('request_method')->label('Método')->badge(),
                TextColumn::make('request_path')->label('Ruta')->searchable()->limit(60),
                TextColumn::make('response_status')->label('Estado')->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 500 => 'danger',
                        $state >= 400 => 'warning',
                        default => 'success',
                    })->sortable(),
                TextColumn::make('response_time_ms')->label('ms')->sortable(),
                TextColumn::make('user.name')->label('Usuario')->placeholder('—'),
                TextColumn::make('ip_address')->label('IP')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('request_method')->label('Método')
                    ->options(array_combine(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])),
                SelectFilter::make('status_class')->label('Estado')
                    ->options(['2' => '2xx', '3' => '3xx', '4' => '4xx', '5' => '5xx'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereBetween('response_status', [(int) $data['value'] * 100, (int) $data['value'] * 100 + 99])
                        : $query),
                DateRangeFilter::make(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('created_at')->label('Fecha')->dateTime(),
                TextEntry::make('trace_id')->label('Trace ID')->copyable(),
                TextEntry::make('request_method')->label('Método')->badge(),
                TextEntry::make('request_path')->label('Ruta'),
                TextEntry::make('response_status')->label('Estado'),
                TextEntry::make('response_time_ms')->label('Tiempo (ms)'),
                TextEntry::make('user.name')->label('Usuario')->placeholder('—'),
                TextEntry::make('ip_address')->label('IP'),
                TextEntry::make('user_agent')->label('User-Agent')->columnSpanFull(),
                Pretty::entry('request_query', 'Query'),
                Pretty::entry('request_body', 'Cuerpo de la petición'),
                Pretty::entry('request_headers', 'Cabeceras'),
                Pretty::entry('response_body', 'Cuerpo de la respuesta'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListApiLogs::route('/')];
    }
}

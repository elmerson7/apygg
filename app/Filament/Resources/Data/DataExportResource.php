<?php

namespace App\Filament\Resources\Data;

use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Resources\Data\Pages\ListDataExports;
use App\Models\DataExport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Exportaciones de datos de plataforma (CSV/XLSX). Solo lectura (nunca se muestran el disco ni la ruta del archivo). */
class DataExportResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = DataExport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Datos';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'data/exports';

    protected static ?string $modelLabel = 'exportación';

    protected static ?string $pluralModelLabel = 'exportaciones';

    protected static ?string $navigationLabel = 'exportaciones';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name,email'))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('exporter')->label('Exportador')->searchable(),
                TextColumn::make('format')->label('Formato'),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('tenant_id')->label('Empresa')->placeholder('Plataforma'),
                TextColumn::make('user.email')->label('Solicitada por')->placeholder('—'),
                TextColumn::make('total_rows')->label('Filas')->placeholder('—'),
                TextColumn::make('size')->label('Tamaño (B)')->placeholder('—'),
                TextColumn::make('error')->label('Error')->limit(40)->placeholder('—'),
                TextColumn::make('created_at')->label('Creada')->dateTime()->sortable(),
                TextColumn::make('expires_at')->label('Caduca')->dateTime()->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(['pending' => 'pending', 'processing' => 'processing', 'completed' => 'completed', 'failed' => 'failed']),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDataExports::route('/')];
    }
}

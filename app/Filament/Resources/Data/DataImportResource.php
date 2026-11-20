<?php

namespace App\Filament\Resources\Data;

use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Resources\Data\Pages\ListDataImports;
use App\Models\DataImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Importaciones de datos de plataforma. Solo lectura (nunca se muestran el disco ni la ruta del archivo). */
class DataImportResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = DataImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Datos';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'data/imports';

    protected static ?string $modelLabel = 'importación';

    protected static ?string $pluralModelLabel = 'importaciones';

    protected static ?string $navigationLabel = 'importaciones';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name,email'))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('importer')->label('Importador')->searchable(),
                TextColumn::make('original_name')->label('Archivo')->limit(30),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('tenant_id')->label('Empresa')->placeholder('Plataforma'),
                TextColumn::make('user.email')->label('Subida por')->placeholder('—'),
                TextColumn::make('total_rows')->label('Filas'),
                TextColumn::make('valid_rows')->label('Válidas'),
                TextColumn::make('invalid_rows')->label('Inválidas'),
                TextColumn::make('created_rows')->label('Creadas'),
                TextColumn::make('error')->label('Error')->limit(40)->placeholder('—'),
                TextColumn::make('created_at')->label('Creada')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(['validating' => 'validating', 'previewed' => 'previewed', 'queued' => 'queued', 'processing' => 'processing', 'completed' => 'completed', 'failed' => 'failed']),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDataImports::route('/')];
    }
}

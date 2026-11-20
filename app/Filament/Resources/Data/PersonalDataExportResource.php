<?php

namespace App\Filament\Resources\Data;

use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Resources\Data\Pages\ListPersonalDataExports;
use App\Models\PersonalDataExport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Exportaciones de datos personales (derecho de acceso, privacidad). Solo lectura (nunca se muestran el disco ni la ruta del archivo). */
class PersonalDataExportResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = PersonalDataExport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Privacidad';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'privacy/personal-data-exports';

    protected static ?string $modelLabel = 'exportación de datos personales';

    protected static ?string $pluralModelLabel = 'datos personales exportados';

    protected static ?string $navigationLabel = 'datos personales exportados';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name,email'))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('user.email')->label('Usuario')->searchable(),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('size')->label('Tamaño (B)')->placeholder('—'),
                TextColumn::make('error')->label('Error')->limit(40)->placeholder('—'),
                TextColumn::make('created_at')->label('Solicitada')->dateTime()->sortable(),
                TextColumn::make('ready_at')->label('Lista')->dateTime()->placeholder('—'),
                TextColumn::make('expires_at')->label('Caduca')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(['pending' => 'pending', 'processing' => 'processing', 'ready' => 'ready', 'failed' => 'failed', 'expired' => 'expired']),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPersonalDataExports::route('/')];
    }
}

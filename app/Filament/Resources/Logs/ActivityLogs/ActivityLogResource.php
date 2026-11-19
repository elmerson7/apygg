<?php

namespace App\Filament\Resources\Logs\ActivityLogs;

use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Resources\Logs\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Support\DateRangeFilter;
use App\Filament\Support\Pretty;
use App\Models\Logs\ActivityLog;
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
use UnitEnum;

/** Log de actividad (altas, cambios y bajas de modelos). Solo lectura; permiso `system.logs`. */
class ActivityLogResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Registros';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'logs/activity';

    protected static ?string $modelLabel = 'registro de actividad';

    protected static ?string $pluralModelLabel = 'actividad';

    protected static ?string $navigationLabel = 'Actividad';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name,email'))
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
                TextColumn::make('user.name')->label('Usuario')->placeholder('Sistema')->searchable(),
                TextColumn::make('action')->label('Acción')->badge(),
                TextColumn::make('model_type')->label('Modelo')->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')->searchable(),
                TextColumn::make('model_id')->label('ID'),
                TextColumn::make('ip_address')->label('IP')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('action')->label('Acción')->options([
                    ActivityLog::ACTION_CREATED => 'Creado',
                    ActivityLog::ACTION_UPDATED => 'Actualizado',
                    ActivityLog::ACTION_DELETED => 'Eliminado',
                    ActivityLog::ACTION_RESTORED => 'Restaurado',
                ]),
                DateRangeFilter::make(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('created_at')->label('Fecha')->dateTime(),
                TextEntry::make('user.name')->label('Usuario')->placeholder('Sistema'),
                TextEntry::make('action')->label('Acción')->badge(),
                TextEntry::make('model_type')->label('Modelo'),
                TextEntry::make('model_id')->label('ID del modelo'),
                TextEntry::make('ip_address')->label('IP'),
                Pretty::entry('old_values', 'Valores anteriores'),
                Pretty::entry('new_values', 'Valores nuevos'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListActivityLogs::route('/')];
    }
}

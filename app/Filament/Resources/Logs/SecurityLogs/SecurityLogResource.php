<?php

namespace App\Filament\Resources\Logs\SecurityLogs;

use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Resources\Logs\SecurityLogs\Pages\ListSecurityLogs;
use App\Filament\Support\DateRangeFilter;
use App\Filament\Support\Pretty;
use App\Models\Logs\SecurityLog;
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

/** Log de seguridad (logins, permisos denegados, bloqueos...). Solo lectura; permiso `system.logs`. */
class SecurityLogResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = SecurityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Registros';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'logs/security';

    protected static ?string $modelLabel = 'registro de seguridad';

    protected static ?string $pluralModelLabel = 'seguridad';

    protected static ?string $navigationLabel = 'Seguridad';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name,email'))
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
                TextColumn::make('event_type')->label('Evento')->badge()->searchable(),
                TextColumn::make('user.name')->label('Usuario')->placeholder('—'),
                TextColumn::make('ip_address')->label('IP')->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('event_type')->label('Evento')
                    ->options(fn (): array => SecurityLog::query()->distinct()->orderBy('event_type')->pluck('event_type', 'event_type')->all()),
                DateRangeFilter::make(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('created_at')->label('Fecha')->dateTime(),
                TextEntry::make('event_type')->label('Evento')->badge(),
                TextEntry::make('user.name')->label('Usuario')->placeholder('—'),
                TextEntry::make('ip_address')->label('IP'),
                TextEntry::make('trace_id')->label('Trace ID')->copyable(),
                TextEntry::make('user_agent')->label('User-Agent'),
                Pretty::entry('details', 'Detalles'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSecurityLogs::route('/')];
    }
}

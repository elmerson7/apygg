<?php

namespace App\Filament\Resources\Audit;

use App\Filament\Resources\Audit\Pages\ListAuditLogs;
use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Support\DateRangeFilter;
use App\Filament\Support\Pretty;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Auditoría de negocio de toda la plataforma (`audit_logs`). Solo lectura, inmutable; permiso `audit.read`. */
class AuditLogResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'audit';

    protected static ?string $modelLabel = 'entrada de auditoría';

    protected static ?string $pluralModelLabel = 'auditoría';

    protected static ?string $navigationLabel = 'Auditoría';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
                TextColumn::make('event')->label('Evento')->badge()->searchable(),
                TextColumn::make('tenant_id')->label('Empresa')->placeholder('Plataforma')->sortable(),
                TextColumn::make('actor_type')->label('Actor')->badge(),
                TextColumn::make('actor_id')->label('ID actor')->placeholder('—'),
                TextColumn::make('auditable_type')->label('Modelo')->formatStateUsing(fn (?string $state): string => $state === null ? '—' : class_basename($state)),
                TextColumn::make('auditable_id')->label('ID')->placeholder('—'),
                TextColumn::make('reason')->label('Motivo')->limit(40)->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Filter::make('tenant_id')->label('Empresa')
                    ->schema([TextInput::make('tenant_id')->label('ID de empresa')->numeric()])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['tenant_id'] ?? null, fn (Builder $q, mixed $id): Builder => $q->where('tenant_id', (int) $id))),
                Filter::make('actor_id')->label('Actor')
                    ->schema([TextInput::make('actor_id')->label('ID de actor')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['actor_id'] ?? null, fn (Builder $q, mixed $id): Builder => $q->where('actor_id', (string) $id))),
                SelectFilter::make('actor_type')->label('Tipo de actor')
                    ->options(['user' => 'user', 'api_key' => 'api_key', 'system' => 'system']),
                Filter::make('event')->label('Evento')
                    ->schema([TextInput::make('event')->label('Evento (exacto)')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['event'] ?? null, fn (Builder $q, mixed $event): Builder => $q->where('event', (string) $event))),
                DateRangeFilter::make(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('created_at')->label('Fecha')->dateTime(),
                TextEntry::make('event')->label('Evento')->badge(),
                TextEntry::make('tenant_id')->label('Empresa')->placeholder('Plataforma'),
                TextEntry::make('actor_type')->label('Actor'),
                TextEntry::make('actor_id')->label('ID actor')->placeholder('—'),
                TextEntry::make('impersonator_id')->label('Impersonador')->placeholder('—'),
                TextEntry::make('auditable_type')->label('Modelo')->placeholder('—'),
                TextEntry::make('auditable_id')->label('ID')->placeholder('—'),
                TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                TextEntry::make('request_id')->label('Request ID')->copyable()->placeholder('—'),
                TextEntry::make('reason')->label('Motivo')->placeholder('—')->columnSpanFull(),
                Pretty::entry('old_values', 'Antes'),
                Pretty::entry('new_values', 'Después'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}

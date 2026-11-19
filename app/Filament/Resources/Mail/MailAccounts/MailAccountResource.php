<?php

namespace App\Filament\Resources\Mail\MailAccounts;

use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Resources\Mail\MailAccounts\Pages\ListMailAccounts;
use App\Models\Mail\MailAccount;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Cuentas de envío de correo: solo lectura (se administran por la API, `mail.manage`).
 * No se muestran credenciales: viven en el proveedor y este panel ni las carga.
 */
class MailAccountResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = MailAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Correo';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'mail/accounts';

    protected static ?string $modelLabel = 'cuenta de correo';

    protected static ?string $pluralModelLabel = 'cuentas de correo';

    protected static ?string $navigationLabel = 'Cuentas';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('mailProvider:id,name,driver'))
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('from_email')->label('Remitente')->searchable(),
                TextColumn::make('mailProvider.name')->label('Proveedor'),
                TextColumn::make('priority')->label('Prioridad')->sortable(),
                IconColumn::make('is_default')->label('Por defecto')->boolean(),
                IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->defaultSort('priority')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('name')->label('Nombre'),
                TextEntry::make('mailProvider.name')->label('Proveedor'),
                TextEntry::make('from_email')->label('Correo remitente'),
                TextEntry::make('from_name')->label('Nombre remitente')->placeholder('—'),
                TextEntry::make('priority')->label('Prioridad'),
                IconEntry::make('is_default')->label('Por defecto')->boolean(),
                IconEntry::make('is_active')->label('Activa')->boolean(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMailAccounts::route('/')];
    }
}

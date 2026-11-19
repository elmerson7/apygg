<?php

namespace App\Filament\Resources\System\Blacklists;

use App\Filament\Resources\System\Blacklists\Pages\CreateBlacklist;
use App\Filament\Resources\System\Blacklists\Pages\ListBlacklists;
use App\Models\Blacklist;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Lista negra de identidades (correo, documento o usuario): quien esté aquí no entra
 * (API ni back-office). Misma tabla y reglas que /security/blacklist; permiso `security.blacklist`.
 */
class BlacklistResource extends Resource
{
    protected static ?string $model = Blacklist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'blacklist';

    protected static ?string $modelLabel = 'bloqueo';

    protected static ?string $pluralModelLabel = 'lista negra';

    protected static ?string $navigationLabel = 'Lista negra';

    /**
     * @param  Model  $record
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identidad a bloquear')
                ->description('Indica al menos una: correo, documento o usuario.')
                ->columns(2)->schema([
                    TextInput::make('email')->label('Correo')->email()->maxLength(255)
                        ->requiredWithoutAll('identity_document,user_id'),
                    TextInput::make('identity_document')->label('Documento de identidad')->maxLength(50)
                        ->requiredWithoutAll('email,user_id'),
                    Select::make('user_id')->label('Usuario')->searchable()
                        ->relationship('user', 'email')->requiredWithoutAll('email,identity_document'),
                    TextInput::make('name')->label('Nombre')->maxLength(255),
                    Textarea::make('reason')->label('Motivo')->required()->maxLength(1000)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name,email', 'addedBy:id,name']))
            ->columns([
                TextColumn::make('email')->label('Correo')->searchable()->placeholder('—'),
                TextColumn::make('identity_document')->label('Documento')->searchable()->placeholder('—'),
                TextColumn::make('user.email')->label('Usuario')->placeholder('—'),
                TextColumn::make('name')->label('Nombre')->searchable()->placeholder('—'),
                TextColumn::make('reason')->label('Motivo')->limit(50),
                TextColumn::make('addedBy.name')->label('Bloqueado por')->placeholder('—'),
                TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([DeleteAction::make()->label('Desbloquear')->modalHeading('Desbloquear identidad')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlacklists::route('/'),
            'create' => CreateBlacklist::route('/create'),
        ];
    }
}

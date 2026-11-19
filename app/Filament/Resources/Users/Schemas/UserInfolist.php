<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cuenta')->columns(2)->schema([
                TextEntry::make('name')->label('Nombre'),
                TextEntry::make('username')->label('Usuario')->placeholder('—'),
                TextEntry::make('email')->label('Correo')->copyable(),
                TextEntry::make('identity_document')->label('Documento')->placeholder('—'),
                TextEntry::make('timezone')->label('Zona horaria')->placeholder('—'),
                TextEntry::make('roles.name')->label('Roles')->badge(),
                IconEntry::make('mfa')->label('MFA activado')->boolean()
                    ->state(fn (User $record): bool => filled($record->getAppAuthenticationSecret())),
                IconEntry::make('active')->label('Activo')->boolean()
                    ->state(fn (User $record): bool => ! $record->trashed()),
                TextEntry::make('email_verified_at')->label('Correo verificado')->dateTime()->placeholder('Sin verificar'),
                TextEntry::make('created_at')->label('Creado')->dateTime(),
                TextEntry::make('deleted_at')->label('Desactivado')->dateTime()->visible(fn (User $record): bool => $record->trashed()),
            ]),
        ]);
    }
}

<?php

namespace App\Filament\Resources\Roles\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rol')->columns(2)->schema([
                TextEntry::make('name')->label('Nombre técnico'),
                TextEntry::make('display_name')->label('Nombre visible'),
                TextEntry::make('description')->label('Descripción')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Permisos')->schema([
                TextEntry::make('permissions.name')->label('Permisos')->badge()->placeholder('Sin permisos'),
            ]),
        ]);
    }
}

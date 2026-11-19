<?php

namespace App\Filament\Resources\Roles\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('permissions'))
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('display_name')->label('Nombre visible')->searchable(),
                TextColumn::make('permissions_count')->label('Permisos')->sortable(),
                TextColumn::make('description')->label('Descripción')->limit(60)->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([ViewAction::make()]);
    }
}

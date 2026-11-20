<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** Miembros de la empresa (cualquier estado de membresía). Solo lectura: se gestionan desde el propio tenant. */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Miembros';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable(),
                TextColumn::make('email')->label('Correo')->searchable(),
                TextColumn::make('pivot.role')->label('Rol en la empresa')->badge(),
                TextColumn::make('pivot.status')->label('Membresía')->badge()
                    ->color(fn (?string $state): string => $state === 'active' ? 'success' : 'warning'),
                TextColumn::make('pivot.joined_at')->label('Desde')->dateTime(),
            ])
            ->defaultSort('name');
    }
}

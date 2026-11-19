<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\Role;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('email')->label('Correo')->searchable()->sortable(),
                TextColumn::make('username')->label('Usuario')->searchable()->toggleable(),
                TextColumn::make('roles.name')->label('Roles')->badge(),
                IconColumn::make('active')->label('Activo')->boolean()
                    ->state(fn (User $record): bool => ! $record->trashed()),
                TextColumn::make('created_at')->label('Creado')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // Por defecto solo activos; "Con desactivados" / "Solo desactivados" para el resto.
                TrashedFilter::make()->label('Desactivados'),
                SelectFilter::make('role')->label('Rol')
                    ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('roles', fn (Builder $roles) => $roles->where('roles.name', $data['value']))
                        : $query),
            ])
            // Editar, activar/desactivar y roles viven en la ficha: así el listado solo consulta
            // la policy de "ver" (las de edición dejan rastro de seguridad en cada comprobación).
            ->recordActions([ViewAction::make()]);
    }
}

<?php

namespace App\Filament\Resources\Permissions;

use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Models\Permission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Permisos: SOLO LECTURA. Se definen en código (PermissionSeeder::catalog()) y un test los
 * compara con lo que usa el código, así que el panel no los crea ni los edita.
 */
class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Acceso';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'permissions';

    protected static ?string $modelLabel = 'permiso';

    protected static ?string $pluralModelLabel = 'permisos';

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @param  Model  $record
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    /**
     * @param  Model  $record
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')->label('Permiso')->searchable()->sortable(),
                TextColumn::make('display_name')->label('Nombre visible')->searchable(),
                TextColumn::make('resource')->label('Recurso')->sortable()->toggleable(),
                TextColumn::make('roles.name')->label('Roles')->badge(),
                TextColumn::make('description')->label('Descripción')->limit(70)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPermissions::route('/')];
    }
}

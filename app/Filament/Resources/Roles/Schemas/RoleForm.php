<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

/**
 * Mismas reglas que StoreRoleRequest / UpdateRoleRequest.
 */
class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rol')->columns(2)->schema([
                // El nombre del rol admin es la base de "ser administrador": no se renombra.
                TextInput::make('name')->label('Nombre técnico')->required()->maxLength(50)
                    ->regex('/^[a-z0-9_-]+$/')
                    ->validationMessages(['regex' => 'Solo minúsculas, números, guion y guion bajo'])
                    ->unique('roles', 'name', ignoreRecord: true)
                    ->disabled(fn (?Role $record): bool => $record?->name === 'admin'),
                TextInput::make('display_name')->label('Nombre visible')->required()->maxLength(100),
                Textarea::make('description')->label('Descripción')->maxLength(255)->columnSpanFull(),
            ]),
            Section::make('Permisos')->schema([
                CheckboxList::make('permission_ids')->label('Permisos del rol')
                    ->options(fn (): array => Permission::query()->orderBy('name')->get()
                        ->mapWithKeys(fn (Permission $permission): array => [
                            $permission->id => $permission->name.' — '.($permission->display_name ?: ''),
                        ])->all())
                    ->columns(2)->searchable()->bulkToggleable()
                    // Nadie concede permisos que no tiene: los que no tienes salen bloqueados.
                    ->disableOptionWhen(fn (int|string $value): bool => ! self::actorCanGrant((int) $value))
                    ->disabled(fn (?Role $record): bool => ! Gate::allows('assignPermission', $record ?? new Role))
                    ->helperText('Requiere el permiso roles.manage-permissions. Los permisos se definen en código (PermissionSeeder).'),
            ]),
        ]);
    }

    public static function actorCanGrant(int $permissionId): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        if ($actor->isAdmin()) {
            return true;
        }

        $name = Permission::query()->whereKey($permissionId)->value('name');

        return $name !== null && $actor->checkPermissionTo($name);
    }
}

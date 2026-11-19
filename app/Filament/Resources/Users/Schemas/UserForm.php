<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

/**
 * Mismas reglas que StoreUserRequest / UpdateUserRequest de la API.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos')->columns(2)->schema([
                TextInput::make('first_name')->label('Nombre')->required()->minLength(2)->maxLength(100),
                TextInput::make('last_name')->label('Apellido')->required()->minLength(2)->maxLength(100),
                TextInput::make('username')->label('Usuario')->required()->minLength(3)->maxLength(50)
                    ->unique('users', 'username', ignoreRecord: true),
                // Como la API: el email solo lo cambia quien tiene users.update, y no el propio.
                TextInput::make('email')->label('Correo')->email()->required()->maxLength(255)
                    ->unique('users', 'email', ignoreRecord: true)
                    ->disabled(fn (?User $record): bool => $record !== null && ! self::canChangeCredentials($record)),
                TextInput::make('identity_document')->label('Documento de identidad')
                    ->required(fn (?User $record): bool => $record === null)
                    ->regex('/^[0-9]{8,12}$/')
                    ->validationMessages(['regex' => 'Debe tener entre 8 y 12 dígitos numéricos'])
                    ->unique('users', 'identity_document', ignoreRecord: true),
                TextInput::make('password')->label('Contraseña')->password()->revealable()
                    ->required(fn (?User $record): bool => $record === null)
                    ->rule(StrongPassword::basic())->minLength(8)
                    ->helperText(fn (?User $record): ?string => $record !== null ? 'Déjala vacía para no cambiarla.' : null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->visible(fn (?User $record): bool => $record === null || self::canChangeCredentials($record)),
            ]),
            Section::make('Roles')->visibleOn('create')->schema([
                Select::make('role_ids')->label('Roles')->multiple()->required()->minItems(1)
                    ->options(fn (): array => self::assignableRoles())
                    ->helperText('Solo los roles que tu cuenta puede conceder.'),
            ]),
        ]);
    }

    /** Email y contraseña: solo de otros usuarios y con users.update (como UpdateUserRequest). */
    public static function canChangeCredentials(User $record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->id !== $record->id && $actor->can('users.update');
    }

    /**
     * Roles que el usuario en sesión puede conceder (RolePolicy::assign), por id => nombre.
     *
     * @return array<int, string>
     */
    public static function assignableRoles(): array
    {
        return Role::query()->orderBy('name')->get()
            ->filter(fn (Role $role): bool => Gate::allows('assign', $role))
            ->mapWithKeys(fn (Role $role): array => [$role->id => $role->display_name ?: $role->name])
            ->all();
    }
}

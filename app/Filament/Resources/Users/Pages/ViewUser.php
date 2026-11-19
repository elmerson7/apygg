<?php

namespace App\Filament\Resources\Users\Pages;

use App\Contracts\UserServiceInterface;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Failure;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            $this->assignRolesAction(),
            $this->deactivateAction(),
            $this->activateAction(),
        ];
    }

    /**
     * Misma ruta que POST /users/{id}/roles: UserPolicy::manageRoles sobre el usuario, RolePolicy::assign
     * por cada rol que se añade o se quita, y UserService::assignRoles (que protege al último admin).
     */
    private function assignRolesAction(): Action
    {
        return Action::make('assignRoles')
            ->label('Asignar roles')
            ->icon('heroicon-o-user-group')
            ->visible(fn (User $record): bool => ! $record->trashed() && Gate::allows('manageRoles', $record))
            ->fillForm(fn (User $record): array => ['role_ids' => $record->roles->pluck('id')->all()])
            ->schema([
                Select::make('role_ids')->label('Roles')->multiple()->required()->minItems(1)->maxItems(10)
                    // Los asignables más los que ya tiene (para mostrarlos); lo demás ni se ofrece.
                    ->options(fn (User $record): array => UserForm::assignableRoles()
                        + $record->roles->mapWithKeys(fn (Role $role): array => [$role->id => $role->display_name ?: $role->name])->all()),
            ])
            ->action(function (array $data, User $record): void {
                $done = Failure::guard(function () use ($data, $record): bool {
                    Gate::authorize('manageRoles', $record);

                    $roleIds = array_map('intval', $data['role_ids']);
                    $currentIds = $record->roles->pluck('id')->map(fn ($id): int => (int) $id)->all();
                    $changed = array_merge(array_diff($roleIds, $currentIds), array_diff($currentIds, $roleIds));

                    foreach (Role::whereIn('id', $changed)->get() as $role) {
                        Gate::authorize('assign', $role);
                    }

                    app(UserServiceInterface::class)->assignRoles((string) $record->id, $roleIds);

                    return true;
                });

                if ($done) {
                    Notification::make()->success()->title('Roles actualizados')->send();
                    $record->load('roles');
                }
            });
    }

    /** Desactivar = UserService::delete (borrado lógico; no deja el sistema sin admins ni te desactiva a ti). */
    private function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label('Desactivar')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('El usuario dejará de poder entrar (API y back-office). Se puede volver a activar.')
            ->visible(fn (User $record): bool => ! $record->trashed() && Gate::allows('delete', $record))
            ->action(function (User $record): void {
                $done = Failure::guard(function () use ($record): bool {
                    Gate::authorize('delete', $record);
                    app(UserServiceInterface::class)->delete((string) $record->id);

                    return true;
                });

                if ($done) {
                    Notification::make()->success()->title('Usuario desactivado')->send();
                    $this->redirect(UserResource::getUrl('view', ['record' => $record]));
                }
            });
    }

    private function activateAction(): Action
    {
        return Action::make('activate')
            ->label('Activar')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (User $record): bool => $record->trashed() && Gate::allows('restore', $record))
            ->action(function (User $record): void {
                $done = Failure::guard(function () use ($record): bool {
                    Gate::authorize('restore', $record);
                    app(UserServiceInterface::class)->restore((string) $record->id);

                    return true;
                });

                if ($done) {
                    Notification::make()->success()->title('Usuario activado')->send();
                    $this->redirect(UserResource::getUrl('view', ['record' => $record]));
                }
            });
    }
}

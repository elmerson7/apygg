<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Contracts\RoleServiceInterface;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Failure;
use App\Models\Role;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            // RoleService::delete protege al rol admin y a los roles con usuarios.
            Action::make('delete')
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Role $record): bool => Gate::allows('delete', $record))
                ->action(function (Role $record): void {
                    $done = Failure::guard(function () use ($record): bool {
                        Gate::authorize('delete', $record);
                        app(RoleServiceInterface::class)->delete((string) $record->id);

                        return true;
                    });

                    if ($done) {
                        Notification::make()->success()->title('Rol eliminado')->send();
                        $this->redirect(RoleResource::getUrl());
                    }
                }),
        ];
    }
}

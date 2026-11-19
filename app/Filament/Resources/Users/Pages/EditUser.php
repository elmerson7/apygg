<?php

namespace App\Filament\Resources\Users\Pages;

use App\Contracts\UserServiceInterface;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Failure;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /** El nombre y apellido viven en el perfil, no en `users`. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();
        $profile = $user->profile()->first();

        $data['first_name'] = $profile !== null ? $profile->first_name : $user->name;
        $data['last_name'] = $profile !== null ? $profile->last_name : '';

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $updated = Failure::guard(function () use ($record, $data): User {
            Gate::authorize('update', $record);

            // Como UpdateUserRequest: email y contraseña no se cambian en la cuenta propia ni sin
            // users.update; los roles tienen su propia acción (con su propia comprobación).
            if (! UserForm::canChangeCredentials($record)) {
                unset($data['email'], $data['password']);
            }
            unset($data['role_ids']);

            return app(UserServiceInterface::class)->update((string) $record->id, $data);
        });

        if (! $updated instanceof User) {
            $this->halt();
        }

        return $updated;
    }
}

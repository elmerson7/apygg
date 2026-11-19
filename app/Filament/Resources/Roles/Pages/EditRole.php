<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Contracts\RoleServiceInterface;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Support\Failure;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $role */
        $role = $this->getRecord();
        $data['permission_ids'] = $role->permissions()->pluck('permissions.id')->all();

        return $data;
    }

    /**
     * Como PUT /roles/{id}: los datos con RoleService::update; los permisos solo con
     * roles.manage-permissions y únicamente se pueden AÑADIR los que el actor tiene
     * (los que no tiene y el rol ya contiene se conservan).
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        $role = Failure::guard(function () use ($record, $data): Role {
            Gate::authorize('update', $record);

            $service = app(RoleServiceInterface::class);
            $submitted = array_key_exists('permission_ids', $data) ? array_map('intval', $data['permission_ids'] ?? []) : null;
            unset($data['permission_ids']);

            $permissionIds = null;
            if ($submitted !== null) {
                Gate::authorize('assignPermission', $record);

                $current = $record->permissions()->pluck('permissions.id')->map(fn ($id): int => (int) $id)->all();
                $service->assertCanGrantPermissions(auth('web')->user() ?? new User, array_values(array_diff($submitted, $current)));

                $locked = array_filter($current, fn (int $id): bool => ! RoleForm::actorCanGrant($id));
                $permissionIds = array_values(array_unique([...$submitted, ...$locked]));
            }

            $service->update((string) $record->id, $data);

            if ($permissionIds !== null) {
                $service->syncPermissions((string) $record->id, $permissionIds);
            }

            return $record->refresh();
        });

        if (! $role instanceof Role) {
            $this->halt();
        }

        return $role;
    }
}

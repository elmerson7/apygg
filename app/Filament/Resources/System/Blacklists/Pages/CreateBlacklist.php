<?php

namespace App\Filament\Resources\System\Blacklists\Pages;

use App\Contracts\SecurityServiceInterface;
use App\Filament\Resources\System\Blacklists\BlacklistResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBlacklist extends CreateRecord
{
    protected static string $resource = BlacklistResource::class;

    /** Como POST /security/blacklist: quién bloquea queda registrado. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['added_by'] = auth('web')->id();

        return $data;
    }

    /** Bloquear corta también las sesiones vivas (JWT y API keys): lo hace el servicio. */
    protected function handleRecordCreation(array $data): Model
    {
        $addedBy = $data['added_by'] ?? null;
        unset($data['added_by']);

        return app(SecurityServiceInterface::class)->blockIdentity($data, $addedBy);
    }
}

<?php

namespace App\Filament\Resources\System\Blacklists\Pages;

use App\Filament\Resources\System\Blacklists\BlacklistResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlacklist extends CreateRecord
{
    protected static string $resource = BlacklistResource::class;

    /** Como POST /security/blacklist: quién bloquea queda registrado. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['added_by'] = auth('web')->id();

        return $data;
    }
}

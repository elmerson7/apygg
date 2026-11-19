<?php

namespace App\Filament\Resources\System\Blacklists\Pages;

use App\Filament\Resources\System\Blacklists\BlacklistResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlacklists extends ListRecords
{
    protected static string $resource = BlacklistResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

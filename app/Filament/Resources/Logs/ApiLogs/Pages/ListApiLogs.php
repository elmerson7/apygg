<?php

namespace App\Filament\Resources\Logs\ApiLogs\Pages;

use App\Filament\Resources\Logs\ApiLogs\ApiLogResource;
use Filament\Resources\Pages\ListRecords;

class ListApiLogs extends ListRecords
{
    protected static string $resource = ApiLogResource::class;
}

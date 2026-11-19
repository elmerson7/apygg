<?php

namespace App\Filament\Resources\Logs\SecurityLogs\Pages;

use App\Filament\Resources\Logs\SecurityLogs\SecurityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListSecurityLogs extends ListRecords
{
    protected static string $resource = SecurityLogResource::class;
}

<?php

namespace App\Filament\Resources\Mail\MailLogs\Pages;

use App\Filament\Resources\Mail\MailLogs\MailLogResource;
use Filament\Resources\Pages\ListRecords;

class ListMailLogs extends ListRecords
{
    protected static string $resource = MailLogResource::class;
}

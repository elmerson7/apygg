<?php

namespace App\Filament\Resources\Mail\MailTemplates\Pages;

use App\Filament\Resources\Mail\MailTemplates\MailTemplateConfigResource;
use Filament\Resources\Pages\ListRecords;

class ListMailTemplates extends ListRecords
{
    protected static string $resource = MailTemplateConfigResource::class;
}

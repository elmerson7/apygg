<?php

namespace App\Filament\Resources\Data\Pages;

use App\Filament\Resources\Data\PersonalDataExportResource;
use Filament\Resources\Pages\ListRecords;

class ListPersonalDataExports extends ListRecords
{
    protected static string $resource = PersonalDataExportResource::class;
}

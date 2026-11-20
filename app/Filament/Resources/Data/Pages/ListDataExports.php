<?php

namespace App\Filament\Resources\Data\Pages;

use App\Filament\Resources\Data\DataExportResource;
use Filament\Resources\Pages\ListRecords;

class ListDataExports extends ListRecords
{
    protected static string $resource = DataExportResource::class;
}

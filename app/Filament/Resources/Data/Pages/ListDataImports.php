<?php

namespace App\Filament\Resources\Data\Pages;

use App\Filament\Resources\Data\DataImportResource;
use Filament\Resources\Pages\ListRecords;

class ListDataImports extends ListRecords
{
    protected static string $resource = DataImportResource::class;
}

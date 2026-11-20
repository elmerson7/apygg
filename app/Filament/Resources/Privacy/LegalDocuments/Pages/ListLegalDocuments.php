<?php

namespace App\Filament\Resources\Privacy\LegalDocuments\Pages;

use App\Filament\Resources\Privacy\LegalDocuments\LegalDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLegalDocuments extends ListRecords
{
    protected static string $resource = LegalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Publicar versión')];
    }
}

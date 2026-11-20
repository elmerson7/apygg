<?php

namespace App\Filament\Resources\Mail\MailSuppressions\Pages;

use App\Filament\Resources\Mail\MailSuppressions\MailSuppressionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMailSuppressions extends ListRecords
{
    protected static string $resource = MailSuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Suprimir una dirección')];
    }
}

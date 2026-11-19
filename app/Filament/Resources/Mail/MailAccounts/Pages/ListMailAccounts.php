<?php

namespace App\Filament\Resources\Mail\MailAccounts\Pages;

use App\Filament\Resources\Mail\MailAccounts\MailAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListMailAccounts extends ListRecords
{
    protected static string $resource = MailAccountResource::class;
}

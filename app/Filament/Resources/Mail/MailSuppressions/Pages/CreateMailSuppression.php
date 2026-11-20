<?php

namespace App\Filament\Resources\Mail\MailSuppressions\Pages;

use App\Contracts\MailSuppressionServiceInterface;
use App\Filament\Resources\Mail\MailSuppressions\MailSuppressionResource;
use App\Models\Mail\MailSuppression;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMailSuppression extends CreateRecord
{
    protected static string $resource = MailSuppressionResource::class;

    /** Alta manual por el servicio (idempotente: lo manual no degrada una queja ya registrada). */
    protected function handleRecordCreation(array $data): Model
    {
        return app(MailSuppressionServiceInterface::class)->suppress(
            (string) $data['email'],
            MailSuppression::REASON_MANUAL,
            'backoffice',
            ['by' => auth('web')->id()],
        );
    }
}

<?php

namespace App\Filament\Resources\System\Settings\Pages;

use App\Contracts\SettingsServiceInterface;
use App\Filament\Resources\System\Settings\SettingsResource;
use App\Filament\Support\Failure;
use App\Models\Settings;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class EditSettings extends EditRecord
{
    protected static string $resource = SettingsResource::class;

    /** Un valor cifrado no se carga en el formulario (se queda vacío = conservar). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Settings $record */
        $record = $this->getRecord();

        if ($record->is_encrypted) {
            $data['value'] = null;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $setting = Failure::guard(function () use ($record, $data): Settings {
            Gate::authorize('update', $record);

            return app(SettingsServiceInterface::class)->update((string) $record->id, $data);
        });

        if (! $setting instanceof Settings) {
            $this->halt();
        }

        return $setting;
    }
}

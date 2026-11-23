<?php

namespace App\Filament\Resources\System\Settings\Pages;

use App\Contracts\SettingsServiceInterface;
use App\Filament\Resources\System\Settings\SettingsResource;
use App\Filament\Support\Failure;
use App\Models\Settings;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class CreateSettings extends CreateRecord
{
    protected static string $resource = SettingsResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $setting = Failure::guard(function () use ($data): Settings {
            Gate::authorize('create', Settings::class);

            return app(SettingsServiceInterface::class)->create([...$data, 'is_public' => (bool) ($data['is_public'] ?? false), 'is_encrypted' => (bool) ($data['is_encrypted'] ?? false)]);
        });

        if (! $setting instanceof Settings) {
            throw new Halt;
        }

        return $setting;
    }
}

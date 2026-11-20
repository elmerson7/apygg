<?php

namespace App\Filament\Resources\FeatureFlags\Pages;

use App\Contracts\FeatureFlagServiceInterface;
use App\Filament\Resources\FeatureFlags\FeatureFlagResource;
use App\Filament\Support\Failure;
use App\Models\FeatureFlag;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateFeatureFlag extends CreateRecord
{
    protected static string $resource = FeatureFlagResource::class;

    /** Escribe por el servicio (valida que la flag esté declarada y refresca la caché de definiciones). */
    protected function handleRecordCreation(array $data): Model
    {
        $key = (string) $data['key'];
        unset($data['key']);

        $done = Failure::guard(fn (): array => app(FeatureFlagServiceInterface::class)->setFlag($key, $data));

        if ($done === null) {
            throw new Halt;
        }

        return FeatureFlag::query()->where('key', $key)->firstOrFail();
    }
}

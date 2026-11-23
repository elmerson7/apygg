<?php

namespace App\Filament\Resources\FeatureFlags\Pages;

use App\Contracts\FeatureFlagServiceInterface;
use App\Filament\Resources\FeatureFlags\FeatureFlagResource;
use App\Filament\Support\Failure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class EditFeatureFlag extends EditRecord
{
    protected static string $resource = FeatureFlagResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $done = Failure::guard(fn (): array => app(FeatureFlagServiceInterface::class)->setFlag((string) $record->getAttribute('key'), $data));

        if ($done === null) {
            throw new Halt;
        }

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Restablecer al valor de config')->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Borra la fila de BD y todos los overrides de esta flag: vuelve a valer lo que diga config/features.php.')
                ->visible(fn (): bool => Gate::allows('delete', $this->record))
                ->action(function (): void {
                    Gate::authorize('delete', $this->record);

                    $done = Failure::guard(function (): true {
                        app(FeatureFlagServiceInterface::class)->resetFlag((string) $this->getRecord()->getAttribute('key'));

                        return true;
                    });

                    if ($done) {
                        Notification::make()->success()->title('Flag restablecida')->send();
                        $this->redirect(FeatureFlagResource::getUrl('index'));
                    }
                }),
        ];
    }
}

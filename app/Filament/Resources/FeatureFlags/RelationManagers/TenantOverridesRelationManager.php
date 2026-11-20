<?php

namespace App\Filament\Resources\FeatureFlags\RelationManagers;

use App\Contracts\FeatureFlagServiceInterface;
use App\Filament\Support\Failure;
use App\Models\FeatureFlagOverride;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** Overrides de la flag por empresa. Escribe y borra con `FeatureFlagServiceInterface` (limpia su caché). */
class TenantOverridesRelationManager extends RelationManager
{
    protected static string $relationship = 'overrides';

    protected static ?string $title = 'Overrides por empresa';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('tenant_id'))
            ->columns([
                TextColumn::make('tenant_id')->label('Empresa')
                    ->formatStateUsing(fn (int $state): string => Tenant::query()->whereKey($state)->value('name').' (#'.$state.')'),
                IconColumn::make('enabled')->label('Activa')->boolean(),
                TextColumn::make('updated_at')->label('Cambiado')->dateTime(),
            ])
            ->headerActions([
                CreateAction::make()->label('Añadir override')
                    ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->schema([
                        Select::make('tenant_id')->label('Empresa')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Tenant::query()->where('name', 'ilike', "%{$search}%")->limit(30)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => Tenant::query()->whereKey($value)->value('name')),
                        Toggle::make('enabled')->label('Activa')->default(true),
                    ])
                    ->using(function (array $data): Model {
                        Gate::authorize('update', $this->getOwnerRecord());

                        $key = (string) $this->getOwnerRecord()->getAttribute('key');
                        $done = Failure::guard(function () use ($key, $data): true {
                            app(FeatureFlagServiceInterface::class)->setTenantOverride($key, (int) $data['tenant_id'], (bool) $data['enabled']);

                            return true;
                        });

                        if ($done === null) {
                            throw new Halt;
                        }

                        return FeatureFlagOverride::query()->where('feature_key', $key)->where('tenant_id', (int) $data['tenant_id'])->firstOrFail();
                    }),
            ])
            ->recordActions([
                Action::make('clear')->label('Quitar')->color('danger')->requiresConfirmation()
                    ->visible(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->action(function (FeatureFlagOverride $record): void {
                        Gate::authorize('update', $this->getOwnerRecord());

                        app(FeatureFlagServiceInterface::class)->clearTenantOverride((string) $record->feature_key, (int) $record->tenant_id);
                        Notification::make()->success()->title('Override quitado')->send();
                    }),
            ]);
    }
}

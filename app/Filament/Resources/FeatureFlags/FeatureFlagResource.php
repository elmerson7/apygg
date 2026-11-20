<?php

namespace App\Filament\Resources\FeatureFlags;

use App\Filament\Resources\FeatureFlags\Pages\CreateFeatureFlag;
use App\Filament\Resources\FeatureFlags\Pages\EditFeatureFlag;
use App\Filament\Resources\FeatureFlags\Pages\ListFeatureFlags;
use App\Filament\Resources\FeatureFlags\RelationManagers\TenantOverridesRelationManager;
use App\Models\FeatureFlag;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Feature flags de la BD (las flags se DECLARAN en `config/features.php`; aquí se sobrescriben sin deploy).
 * Crear y editar llaman a `FeatureFlagServiceInterface::setFlag`; "restablecer" a `resetFlag`. Los overrides
 * por tenant, en el relation manager. Permisos `feature-flags.read` / `feature-flags.manage`.
 */
class FeatureFlagResource extends Resource
{
    protected static ?string $model = FeatureFlag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'feature-flags';

    protected static ?string $modelLabel = 'feature flag';

    protected static ?string $pluralModelLabel = 'feature flags';

    protected static ?string $navigationLabel = 'Feature flags';

    protected static ?string $recordTitleAttribute = 'key';

    /**
     * Claves declaradas en `config/features.php`.
     *
     * @return array<string, string>
     */
    public static function declaredKeys(): array
    {
        return collect(array_keys(array_filter((array) config('features', []), 'is_array')))
            ->mapWithKeys(fn (string $key): array => [$key => $key])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('key')->label('Flag')->required()
                    ->options(fn (): array => self::declaredKeys())
                    ->disabledOn('edit')->dehydrated(),
                Toggle::make('enabled')->label('Activa')->default(false),
                TextInput::make('rollout_percentage')->label('Porcentaje de despliegue')->numeric()->minValue(0)->maxValue(100)->default(100)->required(),
                Select::make('rollout_by')->label('Despliegue por')->options(['user' => 'Usuario', 'tenant' => 'Empresa'])->default('user')->required(),
                TextInput::make('description')->label('Descripción')->maxLength(255)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('overrides'))
            ->columns([
                TextColumn::make('key')->label('Flag')->searchable()->sortable(),
                IconColumn::make('enabled')->label('Activa')->boolean(),
                TextColumn::make('rollout_percentage')->label('%')->suffix('%'),
                TextColumn::make('rollout_by')->label('Por'),
                TextColumn::make('overrides_count')->label('Overrides'),
                TextColumn::make('description')->label('Descripción')->limit(50)->toggleable(),
            ])
            ->defaultSort('key')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [TenantOverridesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeatureFlags::route('/'),
            'create' => CreateFeatureFlag::route('/create'),
            'edit' => EditFeatureFlag::route('/{record}/edit'),
        ];
    }
}

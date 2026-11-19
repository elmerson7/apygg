<?php

namespace App\Filament\Resources\System\Settings;

use App\Contracts\SettingsServiceInterface;
use App\Filament\Resources\System\Settings\Pages\CreateSettings;
use App\Filament\Resources\System\Settings\Pages\EditSettings;
use App\Filament\Resources\System\Settings\Pages\ListSettings;
use App\Filament\Support\Failure;
use App\Models\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Ajustes del sistema (tabla `settings`). Autoriza con SettingsPolicy (solo admin, como la API) y
 * escribe con SettingsService (validación de tipo, cifrado, caché y rastro de actividad).
 * Los valores cifrados nunca se muestran ni se cargan en el formulario.
 */
class SettingsResource extends Resource
{
    protected static ?string $model = Settings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settings';

    protected static ?string $modelLabel = 'ajuste';

    protected static ?string $pluralModelLabel = 'ajustes';

    protected static ?string $recordTitleAttribute = 'key';

    public const TYPES = ['string' => 'Texto', 'integer' => 'Entero', 'boolean' => 'Sí/No', 'json' => 'JSON', 'array' => 'Lista'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('key')->label('Clave')->required()->maxLength(255)
                    ->regex('/^[a-zA-Z0-9._]+$/')
                    ->validationMessages(['regex' => 'Solo letras, números, punto y guion bajo'])
                    ->unique('settings', 'key', ignoreRecord: true),
                Select::make('type')->label('Tipo')->options(self::TYPES)->required()->default('string'),
                Textarea::make('value')->label('Valor')->columnSpanFull()
                    ->required(fn (?Settings $record): bool => $record === null || ! $record->is_encrypted)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (?Settings $record): ?string => $record?->is_encrypted
                        ? 'Valor cifrado: no se muestra. Déjalo vacío para conservarlo.'
                        : null),
                TextInput::make('group')->label('Grupo')->maxLength(100),
                Textarea::make('description')->label('Descripción')->maxLength(1000),
                Toggle::make('is_public')->label('Público (lo ve cualquiera en la API)'),
                Toggle::make('is_encrypted')->label('Cifrado'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label('Clave')->searchable()->sortable(),
                TextColumn::make('group')->label('Grupo')->badge()->sortable()->placeholder('—'),
                TextColumn::make('type')->label('Tipo'),
                TextColumn::make('value')->label('Valor')->limit(50)
                    ->state(fn (Settings $record): string => $record->is_encrypted ? '••••••••' : (string) $record->value),
                IconColumn::make('is_public')->label('Público')->boolean(),
                IconColumn::make('is_encrypted')->label('Cifrado')->boolean(),
            ])
            ->defaultSort('key')
            ->filters([
                SelectFilter::make('group')->label('Grupo')
                    ->options(fn (): array => Settings::query()->whereNotNull('group')->distinct()->orderBy('group')->pluck('group', 'group')->all()),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('delete')
                    ->label('Eliminar')->icon('heroicon-o-trash')->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Settings $record): bool => Gate::allows('delete', $record))
                    ->action(function (Settings $record): void {
                        $done = Failure::guard(function () use ($record): bool {
                            Gate::authorize('delete', $record);
                            app(SettingsServiceInterface::class)->delete((string) $record->id);

                            return true;
                        });

                        if ($done) {
                            Notification::make()->success()->title('Ajuste eliminado')->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSettings::route('/'),
            'create' => CreateSettings::route('/create'),
            'edit' => EditSettings::route('/{record}/edit'),
        ];
    }
}

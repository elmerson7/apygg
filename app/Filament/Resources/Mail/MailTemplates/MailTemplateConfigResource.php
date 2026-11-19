<?php

namespace App\Filament\Resources\Mail\MailTemplates;

use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Resources\Mail\MailTemplates\Pages\ListMailTemplates;
use App\Models\Mail\MailTemplateConfig;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Plantillas de correo: ver y PAUSAR / REANUDAR (el mismo `toggleActive` que POST /admin/mail/templates/{id}/toggle).
 * Una plantilla pausada no se envía (MailSendPolicy). El resto se edita por la API. Permiso `mail.manage`.
 */
class MailTemplateConfigResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = MailTemplateConfig::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Correo';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'mail/templates';

    protected static ?string $modelLabel = 'plantilla de correo';

    protected static ?string $pluralModelLabel = 'plantillas de correo';

    protected static ?string $navigationLabel = 'Plantillas';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('mailAccount:id,name'))
            ->columns([
                TextColumn::make('template_name')->label('Plantilla')->searchable()->sortable(),
                TextColumn::make('template_key')->label('Clave')->searchable(),
                TextColumn::make('mailAccount.name')->label('Cuenta')->placeholder('Por defecto'),
                TextColumn::make('subject_template')->label('Asunto')->limit(50)->toggleable(),
                IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->defaultSort('template_name')
            ->filters([TernaryFilter::make('is_active')->label('Activa')])
            ->recordActions([
                Action::make('pause')
                    ->label('Pausar')->icon('heroicon-o-pause-circle')->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('La plantilla dejará de enviarse hasta que la reanudes.')
                    ->visible(fn (MailTemplateConfig $record): bool => $record->is_active && Gate::allows('update', $record))
                    ->action(fn (MailTemplateConfig $record) => self::toggle($record)),
                Action::make('resume')
                    ->label('Reanudar')->icon('heroicon-o-play-circle')->color('success')
                    ->visible(fn (MailTemplateConfig $record): bool => ! $record->is_active && Gate::allows('update', $record))
                    ->action(fn (MailTemplateConfig $record) => self::toggle($record)),
            ]);
    }

    private static function toggle(MailTemplateConfig $record): void
    {
        Gate::authorize('update', $record);

        $active = $record->toggleActive();

        Notification::make()->success()->title($active ? 'Plantilla reanudada' : 'Plantilla pausada')->send();
    }

    public static function getPages(): array
    {
        return ['index' => ListMailTemplates::route('/')];
    }
}

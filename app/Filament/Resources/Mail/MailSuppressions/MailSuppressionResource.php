<?php

namespace App\Filament\Resources\Mail\MailSuppressions;

use App\Contracts\AuditServiceInterface;
use App\Contracts\MailSuppressionServiceInterface;
use App\Filament\Resources\Mail\MailSuppressions\Pages\CreateMailSuppression;
use App\Filament\Resources\Mail\MailSuppressions\Pages\ListMailSuppressions;
use App\Filament\Support\Failure;
use App\Filament\Support\Pretty;
use App\Models\Mail\MailSuppression;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Lista de supresión de correo (rebotes, quejas, manual). Añadir y quitar llaman a
 * `MailSuppressionServiceInterface`; quitar exige motivo auditado (`mail-suppression.release`). Permiso `mail.suppressions`.
 */
class MailSuppressionResource extends Resource
{
    protected static ?string $model = MailSuppression::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Correo';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'mail/suppressions';

    protected static ?string $modelLabel = 'supresión';

    protected static ?string $pluralModelLabel = 'supresiones de correo';

    protected static ?string $navigationLabel = 'Supresión';

    /**
     * @param  Model  $record
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Suprimir una dirección')
                ->description('No se enviará ningún correo a esta dirección hasta que se quite de la lista.')
                ->schema([
                    TextInput::make('email')->label('Correo')->email()->required()->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->label('Correo')->searchable()->sortable(),
                TextColumn::make('reason')->label('Motivo')->badge()
                    ->color(fn (string $state): string => $state === MailSuppression::REASON_MANUAL ? 'gray' : 'danger'),
                TextColumn::make('source')->label('Origen')->searchable(),
                TextColumn::make('details')->label('Detalle')->state(fn (MailSuppression $record): string => Pretty::json($record->details))
                    ->limit(60)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('suppressed_at')->label('Desde')->dateTime()->sortable(),
            ])
            ->defaultSort('suppressed_at', 'desc')
            ->filters([
                SelectFilter::make('reason')->label('Motivo')->options(array_combine(MailSuppression::REASONS, MailSuppression::REASONS)),
            ])
            ->recordActions([
                Action::make('release')
                    ->label('Quitar')->icon('heroicon-o-trash')->color('danger')
                    ->modalHeading('Quitar de la lista de supresión')
                    ->modalDescription('Volverán a enviarse correos a esta dirección.')
                    ->schema([Textarea::make('reason')->label('Motivo')->required()->maxLength((int) config('audit.reason_max_length', 500))])
                    ->visible(fn (MailSuppression $record): bool => Gate::allows('delete', $record))
                    ->action(function (MailSuppression $record, array $data): void {
                        Gate::authorize('delete', $record);

                        $done = Failure::guard(function () use ($record, $data): true {
                            $audit = app(AuditServiceInterface::class);
                            $reason = $audit->requireReason('mail-suppression.release', $data['reason']);

                            app(MailSuppressionServiceInterface::class)->release($record->email);
                            $audit->recordAction('mail-suppression.release', null, $reason, ['email' => $record->email, 'previous_reason' => $record->reason]);

                            return true;
                        });

                        if ($done) {
                            Notification::make()->success()->title('Dirección quitada de la lista')->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailSuppressions::route('/'),
            'create' => CreateMailSuppression::route('/create'),
        ];
    }
}

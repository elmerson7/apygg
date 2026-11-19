<?php

namespace App\Filament\Resources\Mail\MailLogs;

use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Resources\Mail\MailLogs\Pages\ListMailLogs;
use App\Filament\Support\DateRangeFilter;
use App\Filament\Support\Pretty;
use App\Models\Mail\MailLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Registro de envíos de correo. Solo lectura; permiso `mail.logs`. */
class MailLogResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = MailLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Correo';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'mail/logs';

    protected static ?string $modelLabel = 'envío de correo';

    protected static ?string $pluralModelLabel = 'envíos de correo';

    protected static ?string $navigationLabel = 'Envíos';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('mailAccount:id,name'))
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
                TextColumn::make('to_email')->label('Destinatario')->searchable(),
                TextColumn::make('subject')->label('Asunto')->limit(50)->searchable(),
                TextColumn::make('template_key')->label('Plantilla')->toggleable(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        MailLog::STATUS_SENT, MailLog::STATUS_DELIVERED => 'success',
                        MailLog::STATUS_FAILED, MailLog::STATUS_BOUNCED => 'danger',
                        MailLog::STATUS_SKIPPED => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(array_combine(MailLog::STATUSES, MailLog::STATUSES)),
                SelectFilter::make('template_key')->label('Plantilla')
                    ->options(fn (): array => MailLog::query()->whereNotNull('template_key')->distinct()->orderBy('template_key')->pluck('template_key', 'template_key')->all()),
                DateRangeFilter::make(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('created_at')->label('Fecha')->dateTime(),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('to_email')->label('Destinatario'),
                TextEntry::make('to_name')->label('Nombre')->placeholder('—'),
                TextEntry::make('subject')->label('Asunto')->columnSpanFull(),
                TextEntry::make('template_key')->label('Plantilla')->placeholder('—'),
                TextEntry::make('mailAccount.name')->label('Cuenta')->placeholder('—'),
                TextEntry::make('sent_at')->label('Enviado')->dateTime()->placeholder('—'),
                TextEntry::make('provider_message_id')->label('ID del proveedor')->placeholder('—'),
                TextEntry::make('error_message')->label('Error')->placeholder('—')->columnSpanFull(),
                Pretty::entry('metadata', 'Metadatos'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMailLogs::route('/')];
    }
}

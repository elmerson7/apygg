<?php

namespace App\Filament\Resources\Privacy\LegalDocuments;

use App\Filament\Resources\Privacy\LegalDocuments\Pages\CreateLegalDocument;
use App\Filament\Resources\Privacy\LegalDocuments\Pages\ListLegalDocuments;
use App\Filament\Resources\Privacy\LegalDocuments\Pages\ViewLegalDocument;
use App\Models\LegalDocument;
use App\Models\UserConsent;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * Documentos legales versionados (privacidad). Inmutables: solo se PUBLICA una versión nueva (por
 * `ConsentServiceInterface::publish`); no se editan ni se borran. La ficha agrega las aceptaciones.
 * Permisos `legal-documents.read` / `legal-documents.manage`.
 */
class LegalDocumentResource extends Resource
{
    protected static ?string $model = LegalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Privacidad';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'legal-documents';

    protected static ?string $modelLabel = 'documento legal';

    protected static ?string $pluralModelLabel = 'documentos legales';

    protected static ?string $navigationLabel = 'Documentos legales';

    /**
     * @param  Model  $record
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    /**
     * @param  Model  $record
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Publicar una versión')
                ->description('Los documentos son inmutables: una corrección se publica como versión nueva.')
                ->columns(2)->schema([
                    TextInput::make('key')->label('Clave')->required()->maxLength(64)->regex('/^[a-z0-9][a-z0-9_-]*$/'),
                    TextInput::make('version')->label('Versión')->required()->maxLength(32)->regex('/^[A-Za-z0-9][A-Za-z0-9._-]*$/'),
                    TextInput::make('locale')->label('Idioma')->required()->maxLength(10)->regex('/^[a-z]{2}([_-][A-Za-z]{2})?$/')
                        ->rule(fn ($get) => Rule::unique('legal_documents', 'locale')->where(fn ($q) => $q->where('key', $get('key'))->where('version', $get('version')))),
                    DateTimePicker::make('effective_at')->label('Vigente desde')->helperText('Vacío = ahora (o la de la versión ya publicada).'),
                    TextInput::make('title')->label('Título')->required()->maxLength(191)->columnSpanFull(),
                    Textarea::make('body')->label('Texto')->required()->rows(14)->maxLength(500000)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // El cuerpo puede ser enorme: no se carga en el listado.
            ->modifyQueryUsing(fn ($query) => $query->select(['id', 'key', 'version', 'locale', 'title', 'effective_at', 'created_by', 'created_at']))
            ->columns([
                TextColumn::make('key')->label('Clave')->badge()->searchable()->sortable(),
                TextColumn::make('version')->label('Versión')->sortable(),
                TextColumn::make('locale')->label('Idioma'),
                TextColumn::make('title')->label('Título')->searchable()->limit(50),
                TextColumn::make('effective_at')->label('Vigente desde')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('key')->label('Documento')
                    ->options(fn (): array => LegalDocument::query()->distinct()->orderBy('key')->pluck('key', 'key')->all()),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('key')->label('Clave')->badge(),
                TextEntry::make('version')->label('Versión'),
                TextEntry::make('locale')->label('Idioma'),
                TextEntry::make('effective_at')->label('Vigente desde')->dateTime(),
                TextEntry::make('title')->label('Título')->columnSpanFull(),
                TextEntry::make('content_hash')->label('Huella (sha256)')->copyable()->columnSpanFull(),
            ]),
            Section::make('Aceptaciones de esta versión')->columns(2)->schema([
                TextEntry::make('accepted')->label('Usuarios que la aceptaron')
                    ->state(fn (LegalDocument $record): int => self::distinctUsers($record, UserConsent::ACCEPTED)),
                TextEntry::make('withdrawn')->label('Usuarios que la retiraron')
                    ->state(fn (LegalDocument $record): int => self::distinctUsers($record, UserConsent::WITHDRAWN)),
            ]),
            Section::make('Texto')->schema([
                TextEntry::make('body')->label('')->extraAttributes(['class' => 'whitespace-pre-wrap']),
            ]),
        ]);
    }

    /** Usuarios distintos con alguna entrada del tipo dado para esta clave y versión (agregado: sin datos personales). */
    private static function distinctUsers(LegalDocument $document, string $action): int
    {
        return UserConsent::query()
            ->where('document_key', $document->key)
            ->where('version', $document->version)
            ->where('action', $action)
            ->distinct()
            ->count('user_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegalDocuments::route('/'),
            'create' => CreateLegalDocument::route('/create'),
            'view' => ViewLegalDocument::route('/{record}'),
        ];
    }
}

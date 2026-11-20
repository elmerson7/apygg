<?php

namespace App\Filament\Resources\Tenants;

use App\Enums\TenantStatus;
use App\Filament\Resources\Concerns\IsReadOnly;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Resources\Tenants\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\Tenants\RelationManagers\MembersRelationManager;
use App\Models\Tenant;
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

/**
 * Empresas (tenants) de la plataforma: listado, búsqueda y ficha con miembros e invitaciones.
 * Suspender, reactivar y los límites del plan se hacen desde la ficha, llamando a los servicios
 * (`TenantAdminServiceInterface`, `PlanLimitsInterface`) con motivo auditado. Permisos: `tenants.read` / `tenants.manage`.
 */
class TenantResource extends Resource
{
    use IsReadOnly;

    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'tenants';

    protected static ?string $modelLabel = 'empresa';

    protected static ?string $pluralModelLabel = 'empresas';

    protected static ?string $navigationLabel = 'Empresas';

    protected static ?string $recordTitleAttribute = 'name';

    /** Sin multiempresa no hay empresas que administrar: el recurso no aparece. */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('multi-tenancy.enabled') && parent::shouldRegisterNavigation();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('users'))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->searchable(),
                TextColumn::make('domain')->label('Dominio')->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (TenantStatus $state): string => match ($state) {
                        TenantStatus::Active => 'success',
                        TenantStatus::Suspended => 'danger',
                        TenantStatus::Pending => 'warning',
                    }),
                TextColumn::make('plan')->label('Plan')->placeholder('Por defecto'),
                TextColumn::make('users_count')->label('Miembros')->sortable(),
                TextColumn::make('created_at')->label('Alta')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')
                    ->options(collect(TenantStatus::cases())->mapWithKeys(fn (TenantStatus $s): array => [$s->value => $s->value])->all()),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Empresa')->columns(2)->schema([
                TextEntry::make('name')->label('Nombre'),
                TextEntry::make('slug')->label('Slug'),
                TextEntry::make('domain')->label('Dominio')->placeholder('—'),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('locale')->label('Idioma')->placeholder('—'),
                TextEntry::make('timezone')->label('Zona horaria')->placeholder('—'),
                TextEntry::make('currency')->label('Moneda')->placeholder('—'),
                TextEntry::make('trial_ends_at')->label('Fin de prueba')->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->label('Alta')->dateTime(),
            ]),
            Section::make('Plan y límites')->schema([
                TextEntry::make('plan_overview')->label('')
                    ->state(fn (Tenant $record): string => ViewTenant::describePlan($record))
                    ->extraAttributes(['class' => 'whitespace-pre-wrap']),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [MembersRelationManager::class, InvitationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'view' => ViewTenant::route('/{record}'),
        ];
    }
}

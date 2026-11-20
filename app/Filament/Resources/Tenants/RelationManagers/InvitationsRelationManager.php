<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Filament\Resources\Concerns\QueriesWithoutTenant;
use App\Models\TenantInvitation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** Invitaciones de la empresa. Solo lectura; nunca se muestra el token (ni su hash). */
class InvitationsRelationManager extends RelationManager
{
    use QueriesWithoutTenant;

    protected static string $relationship = 'invitations';

    protected static ?string $title = 'Invitaciones';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->modifyQueryUsing(fn ($query) => $query->with('inviter:id,name'))
            ->columns([
                TextColumn::make('email')->label('Correo')->searchable(),
                TextColumn::make('role')->label('Rol')->badge(),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->state(fn (TenantInvitation $record): string => match (true) {
                        $record->accepted_at !== null => 'aceptada',
                        $record->revoked_at !== null => 'revocada',
                        $record->expires_at->isPast() => 'caducada',
                        default => 'pendiente',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'aceptada' => 'success',
                        'pendiente' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('inviter.name')->label('Invitó')->placeholder('—'),
                TextColumn::make('expires_at')->label('Caduca')->dateTime()->sortable(),
                TextColumn::make('created_at')->label('Creada')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

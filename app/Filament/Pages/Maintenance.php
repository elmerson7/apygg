<?php

namespace App\Filament\Pages;

use App\Contracts\MaintenanceServiceInterface;
use App\Filament\Support\Failure;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Modo mantenimiento de la API (docs/maintenance-mode.md). Página, no recurso: activa y desactiva
 * con `MaintenanceServiceInterface` (que deja la entrada de auditoría). Permiso `maintenance.manage`.
 */
class Maintenance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'maintenance';

    protected static ?string $title = 'Mantenimiento';

    protected string $view = 'filament.pages.maintenance';

    public static function canAccess(): bool
    {
        return (bool) Auth::guard('web')->user()?->can('maintenance.manage');
    }

    /**
     * @return array{message: string|null, until: string|null, since: string, source: string, retry_after: int}|null
     */
    public function state(): ?array
    {
        return app(MaintenanceServiceInterface::class)->state();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enable')
                ->label('Activar mantenimiento')->color('danger')
                ->requiresConfirmation()
                ->modalDescription('La API responderá 503 a todas las peticiones salvo las exentas (salud, webhooks, IP o rol autorizados).')
                ->schema([
                    Textarea::make('message')->label('Mensaje para los clientes')->maxLength(500),
                    DateTimePicker::make('until')->label('Fin estimado')->after('now'),
                ])
                ->visible(fn (): bool => $this->state() === null)
                ->action(function (array $data): void {
                    abort_unless(self::canAccess(), 403);

                    $by = Auth::guard('web')->user();
                    $done = Failure::guard(function () use ($data, $by): true {
                        app(MaintenanceServiceInterface::class)->enable(
                            filled($data['message'] ?? null) ? (string) $data['message'] : null,
                            filled($data['until'] ?? null) ? Carbon::parse($data['until']) : null,
                            $by instanceof User ? $by : null,
                        );

                        return true;
                    });

                    if ($done) {
                        Notification::make()->warning()->title('Mantenimiento activado')->send();
                    }
                }),
            Action::make('disable')
                ->label('Desactivar mantenimiento')->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->state() !== null)
                ->action(function (): void {
                    abort_unless(self::canAccess(), 403);

                    $by = Auth::guard('web')->user();
                    app(MaintenanceServiceInterface::class)->disable($by instanceof User ? $by : null);

                    Notification::make()->success()->title('Mantenimiento desactivado')->send();
                }),
        ];
    }
}

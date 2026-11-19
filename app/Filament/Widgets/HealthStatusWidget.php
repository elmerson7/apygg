<?php

namespace App\Filament\Widgets;

use App\Http\Controllers\Health\HealthController;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Salud de los servicios en el dashboard. Reutiliza HealthController::detailed() (el mismo
 * chequeo de GET /health/detailed): base de datos, Redis, Meilisearch y Horizon.
 */
class HealthStatusWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Salud del sistema';

    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        /** @var array{status?: string, services?: array<string, array<string, mixed>>} $health */
        $health = app(HealthController::class)->detailed()->getData(true);

        $stats = [
            Stat::make('Estado general', $this->label($health['status'] ?? 'unknown'))
                ->color($this->color($health['status'] ?? 'unknown')),
        ];

        foreach ($health['services'] ?? [] as $name => $service) {
            $status = (string) ($service['status'] ?? 'unknown');

            $stats[] = Stat::make(ucfirst($name), $this->label($status))
                ->description(isset($service['latency_ms']) ? $service['latency_ms'].' ms' : ($service['message'] ?? null))
                ->color($this->color($status));
        }

        return $stats;
    }

    private function label(string $status): string
    {
        return match ($status) {
            'healthy', 'ok' => 'Operativo',
            'degraded' => 'Degradado',
            'unhealthy', 'error' => 'Con fallos',
            'not_configured' => 'Sin configurar',
            default => 'Desconocido',
        };
    }

    private function color(string $status): string
    {
        return match ($status) {
            'healthy', 'ok' => 'success',
            'degraded', 'not_configured' => 'warning',
            'unhealthy', 'error' => 'danger',
            default => 'gray',
        };
    }
}

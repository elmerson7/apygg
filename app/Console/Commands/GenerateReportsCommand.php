<?php

namespace App\Console\Commands;

use App\Models\Logs\ActivityLog;
use App\Models\Logs\ApiLog;
use App\Models\Logs\SecurityLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateReportsCommand extends Command
{
    protected $signature = 'reports:generate';

    protected $description = 'Generar reportes semanales del sistema';

    public function handle(): int
    {
        $this->info('Generando reportes semanales...');

        try {
            $startDate = now()->subWeek()->startOfWeek();
            $endDate = now()->subWeek()->endOfWeek();

            $report = [
                'period' => [
                    'start' => $startDate->toDateTimeString(),
                    'end' => $endDate->toDateTimeString(),
                ],
                'users' => [
                    'total' => User::count(),
                    'new' => User::whereBetween('created_at', [$startDate, $endDate])->count(),
                    'active' => User::whereNotNull('last_login_at')
                        ->where('last_login_at', '>=', $startDate)
                        ->count(),
                ],
                'api_logs' => [
                    'total' => ApiLog::whereBetween('created_at', [$startDate, $endDate])->count(),
                    'errors' => ApiLog::whereBetween('created_at', [$startDate, $endDate])
                        ->where('response_status', '>=', 400)
                        ->count(),
                ],
                'security_logs' => [
                    'total' => SecurityLog::whereBetween('created_at', [$startDate, $endDate])->count(),
                    'suspicious' => SecurityLog::whereBetween('created_at', [$startDate, $endDate])
                        ->where('event_type', 'suspicious_activity')
                        ->count(),
                ],
                'activity_logs' => [
                    'total' => ActivityLog::whereBetween('created_at', [$startDate, $endDate])->count(),
                ],
            ];

            $this->info('Reporte semanal generado:');
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            Log::info('Reporte semanal generado', $report);

            // Aquí puedes agregar lógica para:
            // - Guardar el reporte en base de datos
            // - Enviar por email a administradores
            // - Guardar en archivo
            // - Enviar a un servicio externo

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al generar reportes: '.$e->getMessage());
            Log::error('Error al generar reportes', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}

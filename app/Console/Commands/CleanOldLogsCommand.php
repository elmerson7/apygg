<?php

namespace App\Console\Commands;

use App\Models\Logs\ActivityLog;
use App\Models\Logs\ApiLog;
use App\Models\Logs\SecurityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanOldLogsCommand extends Command
{
    protected $signature = 'logs:clean {--days=90 : Días de antigüedad para eliminar logs}';

    protected $description = 'Limpiar logs antiguos según TTL configurado';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->subDays($days);

        $this->info("Limpiando logs anteriores a {$cutoffDate->format('Y-m-d')} ({$days} días)...");

        try {
            $stats = [
                'api_logs' => 0,
                'security_logs' => 0,
                'activity_logs' => 0,
            ];

            // Limpiar logs de API (TTL: 90 días por defecto)
            $stats['api_logs'] = ApiLog::where('created_at', '<', $cutoffDate)->delete();

            // Limpiar logs de seguridad (TTL: 365 días por defecto, pero respetamos el parámetro)
            $securityCutoffDate = now()->subDays(max($days, 365));
            $stats['security_logs'] = SecurityLog::where('created_at', '<', $securityCutoffDate)->delete();

            // Limpiar logs de actividad (TTL: 730 días por defecto, pero respetamos el parámetro)
            $activityCutoffDate = now()->subDays(max($days, 730));
            $stats['activity_logs'] = ActivityLog::where('created_at', '<', $activityCutoffDate)->delete();

            $total = array_sum($stats);

            $this->info('Limpieza completada:');
            $this->line("  - API Logs: {$stats['api_logs']}");
            $this->line("  - Security Logs: {$stats['security_logs']}");
            $this->line("  - Activity Logs: {$stats['activity_logs']}");
            $this->info("Total eliminado: {$total} registros");

            Log::info('Limpieza de logs antiguos completada', [
                'days' => $days,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'stats' => $stats,
                'total' => $total,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al limpiar logs antiguos: '.$e->getMessage());
            Log::error('Error al limpiar logs antiguos', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}

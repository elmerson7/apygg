<?php

namespace App\Jobs;

use App\Models\Logs\ActivityLog;
use Carbon\Carbon;

/**
 * ProcessActivityLogJob
 *
 * Job para procesar logs de actividad (agregación, limpieza, análisis).
 */
class ProcessActivityLogJob extends Job
{
    /**
     * Días de antigüedad para procesar logs
     */
    protected int $daysOld;

    /**
     * Crear una nueva instancia del job
     */
    public function __construct(int $daysOld = 1)
    {
        parent::__construct();
        $this->daysOld = $daysOld;
        $this->queue = 'low'; // Procesamiento de logs es baja prioridad
    }

    /**
     * Ejecutar el job
     */
    protected function process(): void
    {
        $cutoffDate = Carbon::now()->subDays($this->daysOld);

        $this->log('info', 'Iniciando procesamiento de logs de actividad', [
            'days_old' => $this->daysOld,
            'cutoff_date' => $cutoffDate->toDateTimeString(),
        ]);

        // Contar logs a procesar
        $totalLogs = ActivityLog::where('created_at', '<', $cutoffDate)->count();

        if ($totalLogs === 0) {
            $this->log('info', 'No hay logs de actividad para procesar');

            return;
        }

        // Procesar logs en chunks para evitar problemas de memoria
        $processed = 0;
        $chunkSize = 1000;

        ActivityLog::where('created_at', '<', $cutoffDate)
            ->chunk($chunkSize, function ($logs) use (&$processed) {
                foreach ($logs as $log) {
                    // Aquí puedes agregar lógica de procesamiento específica:
                    // - Agregación de métricas por usuario/modelo/acción
                    // - Análisis de patrones de actividad
                    // - Generación de reportes de auditoría
                    // - Archivo de logs antiguos
                    $processed++;
                }
            });

        $this->log('info', 'Procesamiento de logs de actividad completado', [
            'total_processed' => $processed,
            'total_logs' => $totalLogs,
        ]);
    }
}

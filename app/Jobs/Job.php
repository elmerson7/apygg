<?php

namespace App\Jobs;

use App\Services\LogService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Job
 *
 * Clase base para todos los jobs de la aplicación.
 * Proporciona logging integrado, manejo de excepciones,
 * retry automático y notificaciones de fallos.
 */
abstract class Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número máximo de intentos antes de marcar como fallido
     */
    public int $tries = 3;

    /**
     * Tiempo máximo de ejecución en segundos
     */
    public int $timeout = 60;

    /**
     * Tiempo de espera entre reintentos (en segundos)
     * Usa backoff exponencial: 1s, 2s, 4s
     *
     * @var array<int>
     */
    public array $backoff = [1, 2, 4];

    /**
     * Trace ID único para este job
     */
    protected ?string $traceId = null;

    /**
     * Crear una nueva instancia del job
     */
    public function __construct()
    {
        $this->traceId = (string) Str::uuid();
    }

    /**
     * Ejecutar el job
     * Este método envuelve process() con logging y manejo de excepciones
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $jobName = static::class;

        try {
            $this->log('info', "Iniciando ejecución del job: {$jobName}", [
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
            ]);

            // Ejecutar la lógica del job (implementada por clases hijas)
            $this->process();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->log('info', "Job completado exitosamente: {$jobName}", [
                'execution_time_ms' => $executionTime,
                'attempt' => $this->attempts(),
            ]);
        } catch (Throwable $e) {
            $this->handleException($e);

            throw $e; // Re-lanzar para que Laravel maneje el retry
        }
    }

    /**
     * Método que contiene la lógica específica del job
     * Debe ser implementado por las clases hijas
     */
    abstract protected function process(): void;

    /**
     * Manejar excepciones durante la ejecución del job
     */
    protected function handleException(Throwable $exception): void
    {
        $jobName = static::class;
        $attempt = $this->attempts();
        $maxTries = $this->tries;

        $context = [
            'job' => $jobName,
            'attempt' => $attempt,
            'max_tries' => $maxTries,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'trace_id' => $this->traceId,
        ];

        // Log del error
        $this->log('error', "Error en job: {$jobName} - {$exception->getMessage()}", $context);

        // Registrar en ErrorLog usando LogService
        try {
            LogService::logError($exception, array_merge($context, [
                'job_name' => $jobName,
                'job_attempt' => $attempt,
                'job_max_tries' => $maxTries,
            ]));
        } catch (Throwable $e) {
            // Si falla el logging, al menos loguear en canal estándar
            Log::error('Failed to log job error to ErrorLog', [
                'original_error' => $exception->getMessage(),
                'logging_error' => $e->getMessage(),
            ]);
        }

        // Si es el último intento, enviar notificación
        if ($attempt >= $maxTries) {
            $this->notifyFailure($exception, $context);
        }
    }

    /**
     * Notificar sobre fallo del job después de todos los reintentos
     */
    protected function notifyFailure(Throwable $exception, array $context): void
    {
        $jobName = static::class;

        try {
            // Intentar obtener email de administrador desde configuración
            $adminEmail = config('mail.admin_email', config('mail.from.address'));

            if ($adminEmail) {
                NotificationService::sendEmail(
                    $adminEmail,
                    "Job Fallido: {$jobName}",
                    'emails.job-failed',
                    [
                        'job_name' => $jobName,
                        'exception_message' => $exception->getMessage(),
                        'exception_class' => get_class($exception),
                        'attempts' => $this->attempts(),
                        'max_tries' => $this->tries,
                        'trace_id' => $this->traceId,
                        'context' => $context,
                    ],
                    false // Enviar inmediatamente, no en cola
                );
            }

            $this->log('warning', "Notificación de fallo enviada para job: {$jobName}", [
                'admin_email' => $adminEmail,
            ]);
        } catch (Throwable $e) {
            // Si falla la notificación, solo loguear
            $this->log('error', "No se pudo enviar notificación de fallo para job: {$jobName}", [
                'notification_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log helper con contexto enriquecido
     *
     * @param  string  $level  debug, info, warning, error, critical
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $enrichedContext = array_merge([
            'trace_id' => $this->traceId,
            'job' => static::class,
            'queue' => $this->queue ?? 'default',
            'connection' => $this->connection ?? 'redis',
        ], $context);

        LogService::{$level}($message, $enrichedContext);
    }

    /**
     * Obtener trace ID del job
     */
    public function getTraceId(): string
    {
        return $this->traceId ?? (string) Str::uuid();
    }

    /**
     * Método llamado cuando el job falla después de todos los reintentos
     */
    public function failed(Throwable $exception): void
    {
        $this->log('critical', "Job falló definitivamente después de {$this->tries} intentos: ".static::class, [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        // Registrar en ErrorLog con severidad crítica
        try {
            LogService::logError($exception, [
                'job_name' => static::class,
                'job_attempts' => $this->attempts(),
                'job_failed_permanently' => true,
                'trace_id' => $this->traceId,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to log permanent job failure', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

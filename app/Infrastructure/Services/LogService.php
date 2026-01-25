<?php

namespace App\Infrastructure\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * LogService
 * 
 * Servicio centralizado para logging con contexto enriquecido,
 * integración con Sentry y almacenamiento en base de datos.
 * 
 * @package App\Infrastructure\Services
 */
class LogService
{
    /**
     * Trace ID del request actual
     */
    protected static ?string $traceId = null;

    /**
     * Establecer trace ID del request
     */
    public static function setTraceId(?string $traceId = null): void
    {
        self::$traceId = $traceId ?? request()->header('X-Trace-ID') ?? (string) Str::uuid();
    }

    /**
     * Obtener trace ID actual
     */
    public static function getTraceId(): ?string
    {
        if (self::$traceId === null) {
            self::setTraceId();
        }
        return self::$traceId;
    }

    /**
     * Obtener contexto enriquecido para logs
     */
    protected static function getContext(array $additionalContext = []): array
    {
        return array_merge([
            'trace_id' => self::getTraceId(),
            'user_id' => Auth::id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'timestamp' => now()->toIso8601String(),
        ], $additionalContext);
    }

    /**
     * Log genérico con nivel especificado
     *
     * @param string $level debug, info, warning, error, critical
     * @param string $message
     * @param array $context
     * @param string|null $channel
     * @return void
     */
    public static function log(string $level, string $message, array $context = [], ?string $channel = null): void
    {
        $enrichedContext = self::getContext($context);
        
        $logger = $channel ? Log::channel($channel) : Log::getLogger();
        
        $logger->{$level}($message, $enrichedContext);

        // Enviar a Sentry usando captura directa (más confiable que el canal)
        // Verificar nivel según entorno:
        // - dev: solo critical
        // - staging/prod: error y superior
        $shouldSendToSentry = false;
        if (class_exists(\Sentry\SentrySdk::class)) {
            $env = config('app.env', 'dev');
            if ($env === 'dev' && $level === 'critical') {
                $shouldSendToSentry = true;
            } elseif (in_array($env, ['staging', 'prod']) && in_array($level, ['error', 'critical'])) {
                $shouldSendToSentry = true;
            }
        }
        
        if ($shouldSendToSentry) {
            try {
                // Usar captura directa de Sentry (más confiable que el canal)
                self::logToSentry($level, $message, $enrichedContext);
            } catch (\Exception $e) {
                // Silenciar errores de Sentry para no interrumpir el flujo principal
                // Solo loguear en modo debug
                if (config('app.debug')) {
                    \Log::debug('Failed to send to Sentry', [
                        'error' => $e->getMessage(),
                        'level' => $level,
                    ]);
                }
            }
        }
    }

    /**
     * Log de nivel debug
     */
    public static function debug(string $message, array $context = [], ?string $channel = null): void
    {
        self::log('debug', $message, $context, $channel);
    }

    /**
     * Log de nivel info
     */
    public static function info(string $message, array $context = [], ?string $channel = null): void
    {
        self::log('info', $message, $context, $channel);
    }

    /**
     * Log de nivel warning
     */
    public static function warning(string $message, array $context = [], ?string $channel = null): void
    {
        self::log('warning', $message, $context, $channel);
    }

    /**
     * Log de nivel error
     */
    public static function error(string $message, array $context = [], ?string $channel = null): void
    {
        self::log('error', $message, $context, $channel);
    }

    /**
     * Log de nivel critical
     */
    public static function critical(string $message, array $context = [], ?string $channel = null): void
    {
        self::log('critical', $message, $context, $channel);
    }

    /**
     * Log de actividad de API
     */
    public static function logApi(string $method, string $endpoint, int $statusCode, array $context = []): void
    {
        $context = array_merge([
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'response_time_ms' => request()->server('REQUEST_TIME_FLOAT') 
                ? round((microtime(true) - request()->server('REQUEST_TIME_FLOAT')) * 1000, 2)
                : null,
        ], $context);

        self::info("API Request: {$method} {$endpoint} - {$statusCode}", $context, 'api');

        // Guardar en base de datos si el modelo existe
        self::saveToDatabase('api', [
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'request_data' => $context['request_data'] ?? null,
            'response_data' => $context['response_data'] ?? null,
        ]);
    }

    /**
     * Log de actividad del usuario
     */
    public static function logActivity(string $action, string $modelType, ?string $modelId = null, array $context = []): void
    {
        $context = array_merge([
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
        ], $context);

        self::info("Activity: {$action} on {$modelType}" . ($modelId ? " ({$modelId})" : ''), $context, 'activity');

        // Guardar en base de datos
        self::saveToDatabase('activity', [
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'before' => $context['before'] ?? null,
            'after' => $context['after'] ?? null,
        ]);
    }

    /**
     * Log de seguridad
     */
    public static function logSecurity(string $eventType, string $message, array $context = []): void
    {
        $context = array_merge([
            'event_type' => $eventType,
        ], $context);

        self::warning("Security Event: {$eventType} - {$message}", $context, 'security');

        // Guardar en base de datos
        self::saveToDatabase('security', [
            'event_type' => $eventType,
            'message' => $message,
            'details' => $context,
        ]);
    }

    /**
     * Log de errores
     */
    public static function logError(\Throwable $exception, array $context = []): void
    {
        $context = array_merge([
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ], $context);

        self::error("Exception: {$exception->getMessage()}", $context, 'error');

        // Guardar en base de datos
        self::saveToDatabase('error', [
            'severity' => 'error',
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Enviar a Sentry
        self::logToSentry('error', $exception->getMessage(), $context, $exception);
    }

    /**
     * Guardar log en base de datos
     */
    protected static function saveToDatabase(string $type, array $data): void
    {
        try {
            $context = self::getContext();
            
            match($type) {
                'api' => self::saveApiLog($data, $context),
                'activity' => self::saveActivityLog($data, $context),
                'security' => self::saveSecurityLog($data, $context),
                'error' => self::saveErrorLog($data, $context),
                default => null,
            };
        } catch (\Exception $e) {
            // Silenciar errores de base de datos para no interrumpir el flujo principal
            Log::warning('Failed to save log to database', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Guardar API log en base de datos
     */
    protected static function saveApiLog(array $data, array $context): void
    {
        if (!class_exists(\App\Models\Logs\ApiLog::class)) {
            return;
        }

        \App\Models\Logs\ApiLog::create([
            'trace_id' => $context['trace_id'],
            'user_id' => $context['user_id'],
            'method' => $data['method'],
            'endpoint' => $data['endpoint'],
            'status_code' => $data['status_code'],
            'request_data' => $data['request_data'],
            'response_data' => $data['response_data'],
            'ip_address' => $context['ip_address'],
            'user_agent' => $context['user_agent'],
        ]);
    }

    /**
     * Guardar Activity log en base de datos
     */
    protected static function saveActivityLog(array $data, array $context): void
    {
        if (!class_exists(\App\Models\Logs\ActivityLog::class)) {
            return;
        }

        \App\Models\Logs\ActivityLog::create([
            'user_id' => $context['user_id'],
            'model_type' => $data['model_type'],
            'model_id' => $data['model_id'],
            'action' => $data['action'],
            'before' => $data['before'],
            'after' => $data['after'],
            'ip_address' => $context['ip_address'],
            'user_agent' => $context['user_agent'],
        ]);
    }

    /**
     * Guardar Security log en base de datos
     */
    protected static function saveSecurityLog(array $data, array $context): void
    {
        if (!class_exists(\App\Models\Logs\SecurityLog::class)) {
            return;
        }

        \App\Models\Logs\SecurityLog::create([
            'trace_id' => $context['trace_id'],
            'user_id' => $context['user_id'],
            'event_type' => $data['event_type'],
            'message' => $data['message'],
            'details' => $data['details'],
            'ip_address' => $context['ip_address'],
            'user_agent' => $context['user_agent'],
        ]);
    }

    /**
     * Guardar Error log en base de datos
     */
    protected static function saveErrorLog(array $data, array $context): void
    {
        if (!class_exists(\App\Models\Logs\ErrorLog::class)) {
            return;
        }

        \App\Models\Logs\ErrorLog::create([
            'trace_id' => $context['trace_id'],
            'user_id' => $context['user_id'],
            'severity' => $data['severity'],
            'exception' => $data['exception'],
            'message' => $data['message'],
            'file' => $data['file'],
            'line' => $data['line'],
            'trace' => $data['trace'],
            'ip_address' => $context['ip_address'],
            'user_agent' => $context['user_agent'],
        ]);
    }

    /**
     * Enviar log a Sentry
     */
    protected static function logToSentry(string $level, string $message, array $context, ?\Throwable $exception = null): void
    {
        if (!class_exists(\Sentry\SentrySdk::class)) {
            return;
        }

        try {
            $sentryLevel = match($level) {
                'debug' => \Sentry\Severity::debug(),
                'info' => \Sentry\Severity::info(),
                'warning' => \Sentry\Severity::warning(),
                'error' => \Sentry\Severity::error(),
                'critical' => \Sentry\Severity::fatal(),
                default => \Sentry\Severity::error(),
            };

            if ($exception) {
                \Sentry\captureException($exception);
            } else {
                \Sentry\captureMessage($message, $sentryLevel);
            }

            // Agregar contexto adicional
            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($context) {
                foreach ($context as $key => $value) {
                    // setContext requiere un array, setTag para valores simples
                    if (is_array($value)) {
                        $scope->setContext($key, $value);
                    } elseif (is_scalar($value)) {
                        $scope->setTag($key, (string) $value);
                    } else {
                        // Para objetos u otros tipos, convertir a array
                        $scope->setContext($key, ['value' => json_encode($value)]);
                    }
                }
            });
        } catch (\Exception $e) {
            // Silenciar errores de Sentry
        }
    }
}

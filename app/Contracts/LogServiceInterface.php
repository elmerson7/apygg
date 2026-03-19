<?php

namespace App\Contracts;

use Throwable;

/**
 * LogServiceInterface
 *
 * Contrato para el servicio de logging centralizado.
 */
interface LogServiceInterface
{
    /**
     * Establecer trace ID del request
     *
     * @param  string|null  $traceId  Trace ID (opcional, se genera uno si es null)
     * @return void
     */
    public static function setTraceId(?string $traceId = null): void;

    /**
     * Obtener trace ID actual
     *
     * @return string|null  Trace ID actual
     */
    public static function getTraceId(): ?string;

    /**
     * Log genérico con nivel especificado
     *
     * @param  string  $level  debug, info, warning, error, critical
     * @param  string  $message  Mensaje a loggear
     * @param  array   $context  Contexto adicional
     * @param  string|null  $channel  Canal de log (opcional)
     * @return void
     */
    public static function log(string $level, string $message, array $context = [], ?string $channel = null): void;

    /**
     * Log de nivel debug
     *
     * @param  string  $message  Mensaje a loggear
     * @param  array   $context  Contexto adicional
     * @param  string|null  $channel  Canal de log (opcional)
     * @return void
     */
    public static function debug(string $message, array $context = [], ?string $channel = null): void;

    /**
     * Log de nivel info
     *
     * @param  string  $message  Mensaje a loggear
     * @param  array   $context  Contexto adicional
     * @param  string|null  $channel  Canal de log (opcional)
     * @return void
     */
    public static function info(string $message, array $context = [], ?string $channel = null): void;

    /**
     * Log de nivel warning
     *
     * @param  string  $message  Mensaje a loggear
     * @param  array   $context  Contexto adicional
     * @param  string|null  $channel  Canal de log (opcional)
     * @return void
     */
    public static function warning(string $message, array $context = [], ?string $channel = null): void;

    /**
     * Log de nivel error
     *
     * @param  string  $message  Mensaje a loggear
     * @param  array   $context  Contexto adicional
     * @param  string|null  $channel  Canal de log (opcional)
     * @return void
     */
    public static function error(string $message, array $context = [], ?string $channel = null): void;

    /**
     * Log de nivel critical
     *
     * @param  string  $message  Mensaje a loggear
     * @param  array   $context  Contexto adicional
     * @param  string|null  $channel  Canal de log (opcional)
     * @return void
     */
    public static function critical(string $message, array $context = [], ?string $channel = null): void;

    /**
     * Log de actividad de API
     *
     * @param  string  $method  Método HTTP
     * @param  string  $endpoint  Endpoint solicitado
     * @param  int     $statusCode  Código de estado HTTP
     * @param  array   $context  Contexto adicional
     * @return void
     */
    public static function logApi(string $method, string $endpoint, int $statusCode, array $context = []): void;

    /**
     * Log de actividad del usuario
     *
     * @param  string  $action  Acción realizada
     * @param  string  $modelType  Tipo de modelo (ej: 'User', 'Post')
     * @param  string|null  $modelId  ID del modelo (opcional)
     * @param  array   $context  Contexto adicional
     * @return void
     */
    public static function logActivity(string $action, string $modelType, ?string $modelId = null, array $context = []): void;

    /**
     * Log de seguridad
     *
     * @param  string  $eventType  Tipo de evento de seguridad
     * @param  string  $message  Mensaje del evento
     * @param  array   $context  Contexto adicional
     * @return void
     */
    public static function logSecurity(string $eventType, string $message, array $context = []): void;

    /**
     * Log de errores
     *
     * @param  Throwable  $exception  Excepción a loggear
     * @param  array      $context  Contexto adicional
     * @return void
     */
    public static function logError(Throwable $exception, array $context = []): void;
}
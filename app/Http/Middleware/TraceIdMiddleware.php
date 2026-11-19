<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Services\LogService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * TraceIdMiddleware (Request ID)
 *
 * Da a cada petición un identificador único (`X-Request-ID`, alias histórico `X-Trace-ID`)
 * para correlacionar cliente, respuesta y logs. Es middleware GLOBAL: también cubre los
 * 404/405 que ni siquiera llegan a una ruta.
 *
 * - Acepta el ID entrante (`X-Request-ID` o `X-Trace-ID`) solo si es válido: de 1 a 64 caracteres
 *   de `[A-Za-z0-9._:-]` (UUID incluido). Si no, genera un UUID: evita inyección en logs/cabeceras.
 * - Lo devuelve en `X-Request-ID` y `X-Trace-ID` de TODAS las respuestas.
 * - Lo añade al contexto de logs (`trace_id`).
 * - Red de seguridad: una respuesta JSON de error (4xx/5xx) fabricada a mano que no traiga
 *   `code` o `request_id` los recibe aquí, sin tocar status ni campos existentes.
 *
 * Sin estado estático: todo vive en la petición (seguro con Octane).
 */
class TraceIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $this->validOrNull($request->header('X-Request-ID'))
            ?? $this->validOrNull($request->header('X-Trace-ID'))
            ?? (string) Str::uuid();

        // Fijar ID saneado en la petición (el resto del código lee estas cabeceras)
        $request->headers->set('X-Trace-ID', $traceId);
        $request->headers->set('X-Request-ID', $traceId);

        // Sincronizar con LogService para que esté disponible en toda la aplicación
        LogService::setTraceId($traceId);

        // Establecer trace ID en el contexto de logging
        Log::withContext(['trace_id' => $traceId]);

        $response = $next($request);

        $this->completeErrorBody($response, $traceId);

        $response->headers->set('X-Request-ID', $traceId);
        $response->headers->set('X-Trace-ID', $traceId);

        return $response;
    }

    private function validOrNull(?string $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._:\-]{1,64}$/', $value) === 1 ? $value : null;
    }

    /**
     * Completa `code` y `request_id` en un error JSON que no los trae.
     */
    private function completeErrorBody(Response $response, string $traceId): void
    {
        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return;
        }

        $data = $response->getData(true);

        if (! is_array($data) || array_is_list($data)) {
            return;
        }

        $changed = false;

        if (! array_key_exists('code', $data)) {
            $type = is_string($data['type'] ?? null) ? $data['type'] : null;
            $data['code'] = ErrorCode::resolve(null, $response->getStatusCode(), $type);
            $changed = true;
        }

        if (! array_key_exists('request_id', $data)) {
            $data['request_id'] = $traceId;
            $changed = true;
        }

        if ($changed) {
            $response->setData($data);
        }
    }
}

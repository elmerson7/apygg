<?php

namespace App\Services\LLM;

use App\Models\Logs\LlmAuditLog;
use App\Services\LogService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LlmAuditService
 *
 * Persiste cada interacción con el LLM en `logs_llm`. La auditoría es
 * obligatoria: sin ella no se puede observar coste ni degradación por módulo,
 * ni atribuir consumo a quien lo disparó.
 *
 * Cualquier fallo de persistencia se registra como warning y se traga: la
 * auditoría nunca debe tumbar el request que la originó.
 */
class LlmAuditService
{
    /**
     * Máximo de caracteres que se guardan de prompt y respuesta. Los prompts
     * largos multiplican el tamaño de la tabla sin aportar nada al diagnóstico.
     */
    private const TEXT_LIMIT = 10000;

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>  $options
     */
    public function record(
        string $useCase,
        array $messages,
        ?LlmResponse $response,
        ?string $module = null,
        ?array $meta = null,
        array $options = [],
        ?string $error = null,
    ): void {
        $promptText = implode("\n", array_map(
            fn (array $m): string => is_string($m['content'] ?? null) ? $m['content'] : json_encode($m['content'] ?? ''),
            $messages
        ));

        $status = match (true) {
            $error !== null => LlmAuditLog::STATUS_ERROR,
            $response !== null && $response->isOk() => LlmAuditLog::STATUS_SUCCESS,
            default => LlmAuditLog::STATUS_SKIPPED,
        };

        try {
            $this->inSavepoint(function () use ($useCase, $response, $module, $meta, $error, $promptText, $status): void {
                LlmAuditLog::create([
                    'use_case' => $useCase,
                    'module' => $module,
                    'model' => $response?->model,
                    'prompt_tokens' => $response !== null ? $response->promptTokens : 0,
                    'completion_tokens' => $response !== null ? $response->completionTokens : 0,
                    'total_tokens' => $response !== null ? $response->totalTokens() : 0,
                    'status' => $status,
                    'error' => $error,
                    'prompt' => mb_substr($promptText, 0, self::TEXT_LIMIT),
                    'response' => $response !== null ? mb_substr($response->content, 0, self::TEXT_LIMIT) : null,
                    'meta' => $meta,
                    'actor_id' => Auth::id(),
                    'trace_id' => LogService::getTraceId(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('LlmAuditService: no se pudo persistir la auditoría', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Ejecuta el callback dentro de una transacción anidada (SAVEPOINT) cuando
     * ya hay una transacción abierta.
     *
     * Motivo: en PostgreSQL, un error dentro de una transacción la marca como
     * abortada y TODAS las consultas siguientes fallan hasta el rollback. Sin
     * el savepoint, un fallo al auditar (por ejemplo, la tabla aún sin migrar)
     * rompería la operación de negocio que envolvía la llamada al LLM.
     */
    protected function inSavepoint(callable $callback): void
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() === 0) {
            $callback();

            return;
        }

        $connection->transaction($callback);
    }
}

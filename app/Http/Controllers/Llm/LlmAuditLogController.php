<?php

namespace App\Http\Controllers\Llm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Llm\LlmAuditLogResource;
use App\Models\Logs\LlmAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LlmAuditLogController
 *
 * Consulta de la auditoría de IA: qué se llamó, con qué modelo, cuántos tokens
 * costó y si falló. Es la base para controlar el gasto.
 */
class LlmAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = LlmAuditLog::query()
            ->with('actor:id,name,email')
            ->when($request->filled('use_case'), fn ($q) => $q->where('use_case', $request->string('use_case')))
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->string('module')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return $this->sendPaginated($logs);
    }

    public function show(LlmAuditLog $llmLog): JsonResponse
    {
        return $this->sendSuccess(
            new LlmAuditLogResource($llmLog->load('actor:id,name,email')),
            'Registro obtenido exitosamente'
        );
    }

    /**
     * Consumo de tokens por caso de uso y por módulo en el periodo indicado.
     */
    public function stats(Request $request): JsonResponse
    {
        $from = $request->date('from') ?? now()->subDays(30);
        $to = $request->date('to') ?? now();

        $base = fn () => LlmAuditLog::query()->whereBetween('created_at', [$from, $to]);

        return $this->sendSuccess([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'calls' => $base()->count(),
            'total_tokens' => (int) $base()->sum('total_tokens'),
            'by_status' => $base()->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')->pluck('total', 'status'),
            'by_use_case' => $base()->selectRaw('use_case, COUNT(*) as calls, SUM(total_tokens) as tokens')
                ->groupBy('use_case')->orderByDesc('tokens')->limit(20)->get(),
            'by_model' => $base()->whereNotNull('model')
                ->selectRaw('model, COUNT(*) as calls, SUM(total_tokens) as tokens')
                ->groupBy('model')->orderByDesc('tokens')->limit(20)->get(),
        ], 'Estadísticas de consumo obtenidas exitosamente');
    }
}

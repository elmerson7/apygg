<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\ApiController;
use App\Http\Resources\Mail\MailLogResource;
use App\Models\Mail\MailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MailLogController
 *
 * Consulta del historial de correos: a quién se envió, con qué plantilla y en
 * qué estado terminó. Es la respuesta a "¿le llegó el correo al usuario?".
 */
class MailLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $logs = MailLog::query()
            ->with('mailAccount')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('template_key'), fn ($q) => $q->where('template_key', $request->string('template_key')))
            ->when($request->filled('to_email'), fn ($q) => $q->where('to_email', 'ILIKE', '%'.$request->string('to_email').'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return $this->sendPaginated($logs);
    }

    public function show(MailLog $mailLog): JsonResponse
    {
        return $this->sendSuccess(
            new MailLogResource($mailLog->load(['mailAccount', 'events'])),
            'Registro de correo obtenido exitosamente'
        );
    }

    /**
     * Resumen por estado para el periodo indicado (por defecto, 30 días).
     */
    public function stats(Request $request): JsonResponse
    {
        $from = $request->date('from') ?? now()->subDays(30);
        $to = $request->date('to') ?? now();

        $byStatus = MailLog::query()
            ->forDateRange($from, $to)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byTemplate = MailLog::query()
            ->forDateRange($from, $to)
            ->whereNotNull('template_key')
            ->selectRaw('template_key, COUNT(*) as total')
            ->groupBy('template_key')
            ->orderByDesc('total')
            ->limit(20)
            ->pluck('total', 'template_key');

        return $this->sendSuccess([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'total' => (int) $byStatus->sum(),
            'by_status' => $byStatus,
            'by_template' => $byTemplate,
        ], 'Estadísticas de correo obtenidas exitosamente');
    }
}

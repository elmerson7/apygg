<?php

namespace App\Http\Controllers\Logs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\ListLogsRequest;
use App\Models\Logs\ActivityLog;
use App\Models\Logs\ApiLog;
use App\Models\Logs\SecurityLog;
use App\Support\Listing\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LogsController
 *
 * Expone los logs almacenados en BD vía API.
 * Todos los endpoints requieren auth:api + permiso system.logs.
 * Los endpoints de limpieza requieren permiso system.logs-delete.
 */
class LogsController extends Controller
{
    /**
     * GET /admin/logs/activity
     * Lista activity logs con filtros (`filter[...]`, `sort`; los nombres sueltos de antes siguen valiendo).
     */
    #[Listing(
        filters: ['user_id' => 'int', 'action' => 'exact', 'model_type' => 'partial', 'from' => 'from:created_at', 'to' => 'to:created_at', 'tenant_id' => 'int', 'trace_id' => 'exact'],
        sorts: ['created_at'],
        legacy: ['user_id', 'action', 'model_type', 'from', 'to', 'tenant_id'],
        defaultPerPage: 20,
    )]
    public function activity(ListLogsRequest $request): JsonResponse
    {
        // Multiempresa: la plataforma ve todo; `tenant_id` filtra por empresa (docs/multi-tenancy.md)
        return $this->listing($request, ActivityLog::query());
    }

    /**
     * GET /admin/logs/api
     * Lista api logs con filtros.
     */
    #[Listing(
        filters: ['user_id' => 'int', 'method' => 'custom', 'status_code' => 'int:response_status', 'endpoint' => 'partial:request_path', 'from' => 'from:created_at', 'to' => 'to:created_at', 'tenant_id' => 'int', 'trace_id' => 'exact'],
        sorts: ['created_at', 'status_code' => 'response_status', 'response_time_ms'],
        legacy: ['user_id', 'method', 'status_code', 'endpoint', 'from', 'to', 'tenant_id'],
        defaultPerPage: 20,
    )]
    public function api(ListLogsRequest $request): JsonResponse
    {
        return $this->listing($request, ApiLog::query(), [
            'method' => fn (Builder $q, string $v) => $q->where('request_method', strtoupper($v)),
        ]);
    }

    /**
     * GET /admin/logs/security
     * Lista security logs con filtros.
     */
    #[Listing(
        filters: ['event_type' => 'exact', 'user_id' => 'int', 'ip_address' => 'exact', 'from' => 'from:created_at', 'to' => 'to:created_at', 'tenant_id' => 'int', 'trace_id' => 'exact'],
        sorts: ['created_at'],
        legacy: ['event_type', 'user_id', 'ip_address', 'from', 'to', 'tenant_id'],
        defaultPerPage: 20,
    )]
    public function security(ListLogsRequest $request): JsonResponse
    {
        return $this->listing($request, SecurityLog::query());
    }

    /**
     * DELETE /admin/logs/activity
     * Limpia activity logs anteriores a X días (default: 90).
     */
    public function clearActivity(Request $request): JsonResponse
    {
        $days = max(1, (int) $request->input('days', 90));
        $deleted = ActivityLog::where('created_at', '<', now()->subDays($days))->delete();

        return $this->sendSuccess(null, "{$deleted} activity logs eliminados (anteriores a {$days} días)");
    }

    /**
     * DELETE /admin/logs/api
     * Limpia api logs anteriores a X días (default: 30).
     */
    public function clearApi(Request $request): JsonResponse
    {
        $days = max(1, (int) $request->input('days', 30));
        $deleted = ApiLog::where('created_at', '<', now()->subDays($days))->delete();

        return $this->sendSuccess(null, "{$deleted} api logs eliminados (anteriores a {$days} días)");
    }

    /**
     * DELETE /admin/logs/security
     * Limpia security logs anteriores a X días (default: 180).
     */
    public function clearSecurity(Request $request): JsonResponse
    {
        $days = max(1, (int) $request->input('days', 180));
        $deleted = SecurityLog::where('created_at', '<', now()->subDays($days))->delete();

        return $this->sendSuccess(null, "{$deleted} security logs eliminados (anteriores a {$days} días)");
    }
}

<?php

namespace App\Http\Controllers\Logs;

use App\Http\Controllers\Controller;
use App\Models\Logs\ActivityLog;
use App\Models\Logs\ApiLog;
use App\Models\Logs\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LogsController
 *
 * Expone los logs almacenados en BD vía API.
 * Todos los endpoints requieren auth:api + permiso logs.view.
 * El endpoint de limpieza requiere permiso logs.delete.
 */
class LogsController extends Controller
{
    /**
     * GET /admin/logs/activity
     * Lista activity logs con filtros.
     */
    public function activity(Request $request): JsonResponse
    {
        $query = ActivityLog::query()->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('model_type')) {
            $query->where('model_type', 'like', '%'.$request->input('model_type').'%');
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        $perPage = min(max(1, (int) $request->input('per_page', 20)), 100);

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }

    /**
     * GET /admin/logs/api
     * Lista api logs con filtros.
     */
    public function api(Request $request): JsonResponse
    {
        $query = ApiLog::query()->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('method')) {
            $query->where('method', strtoupper($request->input('method')));
        }
        if ($request->filled('status_code')) {
            $query->where('status_code', $request->input('status_code'));
        }
        if ($request->filled('endpoint')) {
            $query->where('endpoint', 'like', '%'.$request->input('endpoint').'%');
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        $perPage = min(max(1, (int) $request->input('per_page', 20)), 100);

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }

    /**
     * GET /admin/logs/security
     * Lista security logs con filtros.
     */
    public function security(Request $request): JsonResponse
    {
        $query = SecurityLog::query()->latest();

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('ip_address')) {
            $query->where('ip_address', $request->input('ip_address'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        $perPage = min(max(1, (int) $request->input('per_page', 20)), 100);

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }

    /**
     * DELETE /admin/logs/activity
     * Limpia activity logs anteriores a X días (default: 90).
     */
    public function clearActivity(Request $request): JsonResponse
    {
        $days = max(1, (int) $request->input('days', 90));
        $deleted = ActivityLog::where('created_at', '<', now()->subDays($days))->delete();

        return response()->json([
            'success' => true,
            'message' => "{$deleted} activity logs eliminados (anteriores a {$days} días)",
        ]);
    }

    /**
     * DELETE /admin/logs/api
     * Limpia api logs anteriores a X días (default: 30).
     */
    public function clearApi(Request $request): JsonResponse
    {
        $days = max(1, (int) $request->input('days', 30));
        $deleted = ApiLog::where('created_at', '<', now()->subDays($days))->delete();

        return response()->json([
            'success' => true,
            'message' => "{$deleted} api logs eliminados (anteriores a {$days} días)",
        ]);
    }

    /**
     * DELETE /admin/logs/security
     * Limpia security logs anteriores a X días (default: 180).
     */
    public function clearSecurity(Request $request): JsonResponse
    {
        $days = max(1, (int) $request->input('days', 180));
        $deleted = SecurityLog::where('created_at', '<', now()->subDays($days))->delete();

        return response()->json([
            'success' => true,
            'message' => "{$deleted} security logs eliminados (anteriores a {$days} días)",
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use App\Contracts\MaintenanceServiceInterface;
use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckMaintenanceMode: con el modo mantenimiento activo responde 503 RFC 7807 (`code: maintenance`) con
 * `Retry-After`, salvo salud, webhooks entrantes, IPs y roles autorizados (config/maintenance.php).
 * Ver docs/maintenance-mode.md.
 */
class CheckMaintenanceMode
{
    public function __construct(private readonly MaintenanceServiceInterface $maintenance) {}

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->maintenance->state();

        if ($state === null || $this->maintenance->allows($request)) {
            return $next($request);
        }

        return ApiResponse::rfc7807(
            __('messages.errors.maintenance'),
            503,
            $state['message'] ?? __('messages.errors.maintenance_detail'),
            'maintenance',
            $request->path(),
            ['retry_after' => $state['retry_after'], 'until' => $state['until']],
            'maintenance',
        )->header('Retry-After', (string) $state['retry_after']);
    }
}

<?php

namespace App\Http\Middleware;

use App\Contracts\SecurityServiceInterface;
use App\Enums\ErrorCode;
use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege `GET /metrics`: apagado -> 404; sin token ni IPs configurados -> 403; con ellos, basta uno
 * (`Authorization: Bearer <METRICS_TOKEN>` o IP en `METRICS_ALLOWED_IPS`). Nunca público.
 */
class AuthorizeMetrics
{
    public function __construct(private readonly SecurityServiceInterface $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('metrics.enabled')) {
            return ApiResponse::notFound();
        }

        $token = (string) config('metrics.token', '');
        $ips = (array) config('metrics.allowed_ips', []);

        if ($token === '' && $ips === []) {
            return ApiResponse::error('Métricas sin credenciales configuradas.', 403, code: ErrorCode::Forbidden);
        }

        $bearer = (string) $request->bearerToken();

        if ($token !== '' && $bearer !== '' && hash_equals($token, $bearer)) {
            return $next($request);
        }

        if ($ips !== [] && $this->security->isIpWhitelisted((string) $request->ip(), array_values($ips))) {
            return $next($request);
        }

        return ApiResponse::error('No autorizado para consultar las métricas.', 403, code: ErrorCode::Forbidden);
    }
}

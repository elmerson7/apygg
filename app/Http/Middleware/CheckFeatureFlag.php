<?php

namespace App\Http\Middleware;

use App\Contracts\FeatureFlagServiceInterface;
use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckFeatureFlag Middleware
 *
 * Verifica que una feature flag (config/features.php + BD, con override por tenant/usuario) esté habilitada antes de
 * permitir el acceso a la ruta. Si está deshabilitada, responde 403.
 *
 * Complementa al helper `Feature`: en lugar de comprobar la flag dentro del
 * controlador, se declara a nivel de ruta.
 *
 * Uso en rutas:
 *   Route::get('/beta', [BetaController::class, 'index'])
 *       ->middleware('feature:beta_api_features');
 */
class CheckFeatureFlag
{
    public function __construct(private readonly FeatureFlagServiceInterface $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($this->features->disabled($feature)) {
            return ApiResponse::error(
                'Esta funcionalidad no está disponible actualmente',
                403,
                ['feature' => $feature],
                'feature_disabled'
            );
        }

        return $next($request);
    }
}

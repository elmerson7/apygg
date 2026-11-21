<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Helpers\ApiResponse;
use App\Support\ApiPath;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Versión mínima de app (config/app-versions.php). Lee `X-App-Version` y `X-App-Platform` y
 * responde 426 `app_update_required` si la versión es menor que la mínima de esa plataforma.
 * Salta las rutas de `except` (salud, webhooks). Sin cabecera: pasa, salvo `require_header`.
 */
class EnsureMinimumAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app-versions.enabled', false) || $this->excepted($request)) {
            return $next($request);
        }

        $version = trim((string) $request->header('X-App-Version', ''));
        $platform = mb_strtolower(trim((string) $request->header('X-App-Platform', '')));

        if ($version === '') {
            return config('app-versions.require_header', false)
                ? $this->updateRequired($this->minimumFor($platform), $platform)
                : $next($request);
        }

        $minimum = $this->minimumFor($platform);

        if ($minimum !== null && version_compare($version, $minimum, '<')) {
            return $this->updateRequired($minimum, $platform);
        }

        return $next($request);
    }

    private function minimumFor(string $platform): ?string
    {
        /** @var array<string, string|null> $minimums */
        $minimums = (array) config('app-versions.minimum', []);

        $value = $minimums[$platform] ?? $minimums['*'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function updateRequired(?string $minimum, string $platform): Response
    {
        return ApiResponse::error(
            'Esta versión de la aplicación ya no es compatible. Actualiza para continuar.',
            426,
            array_filter(['minimum_version' => $minimum, 'platform' => $platform !== '' ? $platform : null, 'update_url' => config('app-versions.update_url')]),
            null,
            ErrorCode::AppUpdateRequired
        );
    }

    private function excepted(Request $request): bool
    {
        foreach ((array) config('app-versions.except', []) as $pattern) {
            if (ApiPath::is($request, $pattern)) {
                return true;
            }
        }

        return false;
    }
}

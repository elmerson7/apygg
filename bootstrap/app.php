<?php

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Exceptions\BusinessLogicException;
use App\Helpers\ApiResponse;
use App\Http\Middleware\AdaptiveRateLimitingMiddleware;
use App\Http\Middleware\CheckApiKeyScope;
use App\Http\Middleware\CheckFeatureFlag;
use App\Http\Middleware\CompressionMiddleware;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\IpWhitelistMiddleware;
use App\Http\Middleware\LogApiRequests;
use App\Http\Middleware\OptionalAuthMiddleware;
use App\Http\Middleware\RateLimitLoggerMiddleware;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\ResolveClientIpMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SecurityLoggerMiddleware;
use App\Http\Middleware\TraceIdMiddleware;
use App\Providers\AuthServiceProvider;
use App\Services\Logging\ApiLogger;
use App\Services\Logging\SecurityLogger;
use App\Services\LogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use Sentry\Laravel\Integration;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        AuthServiceProvider::class,
    ])
    // Los listeners se registran explícitamente (EventServiceProvider::$listen y
    // AppServiceProvider). El autodescubrimiento de app/Listeners los registraría
    // una segunda vez (cada listener se ejecutaría dos veces).
    ->withEvents(discover: false)
    ->withRouting(
        // Cargar rutas web solo para Telescope y Horizon (necesitan sesiones)
        web: __DIR__.'/../routes/web.php',
        // Cargar rutas API sin prefijo /api (directo en la raíz)
        using: function () {
            Route::middleware('api')
                ->group(base_path('routes/api.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Request ID / Trace ID en TODA petición (también 404/405 sin ruta, que no pasan por el grupo `api`)
        // y `code`/`request_id` en errores fabricados a mano por un middleware.
        $middleware->prepend(TraceIdMiddleware::class);

        // CORS global (antes del enrutado): responde el preflight OPTIONS de cualquier
        // ruta sin necesitar una ruta comodín, y añade las cabeceras al resto.
        $middleware->prepend(CorsMiddleware::class);

        // Idioma de la petición (usuario -> tenant -> Accept-Language -> instalación) y `Content-Language`.
        // Global para que también los 404/405 sin ruta salgan en el idioma resuelto. Restaura el locale al
        // terminar (Octane). Ver docs/i18n.md.
        $middleware->append(SetApiLocale::class);

        // Middleware para API: todas las respuestas en JSON
        $middleware->api(prepend: [
            ResolveClientIpMiddleware::class, // IP real del cliente detrás de Cloudflare (antes que todo lo que use la IP)
            SecurityHeadersMiddleware::class, // Headers de seguridad HTTP
            AdaptiveRateLimitingMiddleware::class, // Rate limiting adaptativo por tipo de endpoint
            ForceJsonResponse::class,
            CompressionMiddleware::class, // Compresión HTTP (gzip/zstd) cuando no se usa Caddy
            LogApiRequests::class, // Registrar requests/responses
            SecurityLoggerMiddleware::class, // Registrar eventos de seguridad y detectar patrones anómalos
            RateLimitLoggerMiddleware::class, // Registrar bloqueos por rate limiting y detectar abuso
        ]);

        // Proxies de confianza (X-Forwarded-*). TRUSTED_PROXIES: lista separada por
        // comas de IPs/CIDR, o '*' para confiar en cualquiera. Por defecto vacío:
        // no se confía en ningún proxy (una cabecera X-Forwarded-* falsificada
        // no puede alterar esquema, host ni IP). Ver .env.example.
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trustedProxies !== '') {
            $middleware->trustProxies(
                at: $trustedProxies === '*'
                    ? '*'
                    : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
            );
        }

        // Registrar middleware con alias para uso en rutas
        $middleware->alias([
            // RBAC: middlewares de spatie/laravel-permission. Se declara siempre el permiso/rol
            // explícito y el guard: `permission:users.read,api`. Ver docs/permissions.md.
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // `auth:api-key` NO es un alias: es el middleware estándar `auth` con el guard `api-key`
            // (config/auth.php, registrado en AuthServiceProvider con Auth::viaRequest).
            'api-key-scope' => CheckApiKeyScope::class,
            'ip.whitelist' => IpWhitelistMiddleware::class,
            'feature' => CheckFeatureFlag::class,
            'auth.optional' => OptionalAuthMiddleware::class,
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'idempotent' => \App\Http\Middleware\EnsureIdempotency::class,
            'tenancy.enabled' => \App\Http\Middleware\EnsureTenancyEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Integración automática de Sentry para captura de excepciones
        // Esto captura automáticamente todas las excepciones y las envía a Sentry
        Integration::handles($exceptions);

        // Back-office (Filament, módulo opcional; ver docs/backoffice.md): sus pantallas y las
        // peticiones de Livewire son HTML/redirecciones, no API. Sin la config, nunca coincide.
        $isBackoffice = function ($request): bool {
            if (! config('backoffice.enabled', false)) {
                return false;
            }

            $path = trim((string) config('backoffice.path', 'backoffice'), '/');

            return $request->is($path, $path.'/*', 'livewire*');
        };

        // Forzar respuestas JSON para todas las excepciones (API-only), salvo el back-office
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) use ($isBackoffice) {
            return ! $isBackoffice($request);
        });

        $exceptions->render(function (Throwable $e, $request) use ($isBackoffice) {
            // El back-office usa el render estándar de Laravel/Filament (redirige al login, páginas 403/404 HTML).
            if ($isBackoffice($request)) {
                return null;
            }

            // Helper para registrar request/response en logs_api (incluso para excepciones)
            $logApiRequest = function (Throwable $exception, $req) {
                try {
                    // Solo registrar si es una excepción HTTP (404, 403, etc.)
                    if ($exception instanceof HttpException) {
                        $statusCode = $exception->getStatusCode();

                        // Crear respuesta simulada para el logger
                        $response = new Response(
                            ['error' => $exception->getMessage()],
                            $statusCode
                        );

                        // Registrar en logs_api
                        ApiLogger::logRequest($req, $response);
                    }
                } catch (Throwable $logError) {
                    // Silenciar errores de logging para no interrumpir el flujo principal
                }
            };

            // Helper para logging de excepciones
            $logException = function (Throwable $exception, $req, bool $isCritical = false): void {
                try {
                    $context = [
                        'exception' => get_class($exception),
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'url' => $req->fullUrl(),
                        'method' => $req->method(),
                        'ip' => $req->ip(),
                        'user_agent' => $req->userAgent(),
                    ];

                    // Agregar usuario si está autenticado
                    if (auth()->check()) {
                        $context['user_id'] = auth()->id();
                    }

                    // Agregar trace_id si existe
                    if ($traceId = $req->header('X-Trace-ID') ?? $req->header('X-Request-ID')) {
                        $context['trace_id'] = $traceId;
                    }

                    // Log según severidad
                    if ($isCritical) {
                        LogService::logError($exception, $context);
                    } else {
                        LogService::warning("Exception: {$exception->getMessage()}", $context);
                    }
                } catch (Throwable $logError) {
                    // Silenciar errores de logging para no interrumpir el flujo principal
                }
            };

            // Helper para obtener tipo de error según código HTTP
            $getErrorType = function (int $statusCode): string {
                return match ($statusCode) {
                    400 => 'bad_request',
                    401 => 'unauthorized',
                    403 => 'forbidden',
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    409 => 'conflict',
                    422 => 'validation_error',
                    429 => 'rate_limit_exceeded',
                    500 => 'internal_server_error',
                    502 => 'bad_gateway',
                    503 => 'service_unavailable',
                    504 => 'gateway_timeout',
                    default => 'http_error',
                };
            };

            // ============================================
            // Excepciones Personalizadas
            // ============================================

            // ApiException (con RFC 7807)
            if ($e instanceof ApiException) {
                $logException($e, $request, $e->getStatusCode() >= 500);

                return ApiResponse::rfc7807(
                    $e->getMessage(),
                    $e->getStatusCode(),
                    $e->getMessage(),
                    $e->getType() ?? 'about:blank',
                    $e->getInstance() ?? $request->path(),
                    array_merge(
                        $e->getExtensions(),
                        ! empty($e->getErrors()) ? ['errors' => $e->getErrors()] : []
                    ),
                    $e->errorCode()
                );
            }

            // BusinessLogicException
            if ($e instanceof BusinessLogicException) {
                $logException($e, $request, false);

                return ApiResponse::rfc7807(
                    $e->getMessage(),
                    422,
                    $e->getMessage(),
                    'business_logic_error',
                    $request->path(),
                    $e->getExtensions()
                );
            }

            // ============================================
            // Excepciones de Laravel/Symfony
            // ============================================

            // 404 - Ruta no encontrada
            if ($e instanceof NotFoundHttpException) {
                $logException($e, $request, false);

                // Registrar en logs_api antes de devolver respuesta
                $logApiRequest($e, $request);

                return ApiResponse::rfc7807(
                    __('messages.errors.endpoint_not_found'),
                    404,
                    __('messages.errors.endpoint_not_found_detail'),
                    'not_found',
                    $request->path(),
                    ['path' => $request->path()]
                );
            }

            // ValidationException (422)
            if ($e instanceof ValidationException) {
                $logException($e, $request, false);

                return ApiResponse::validation($e->errors(), __('messages.errors.validation'));
            }

            // Violación de restricción única: la validación `unique` no cierra la carrera entre
            // dos peticiones simultáneas; la BD sí. Se responde 422 (como la validación) y no 500,
            // sin exponer el SQL ni el valor duplicado.
            if ($e instanceof UniqueConstraintViolationException) {
                $logException($e, $request, false);

                $field = preg_match('/Key \(([a-z0-9_, ]+)\)=/i', $e->getMessage(), $m) ? trim(explode(',', $m[1])[0]) : 'resource';

                return ApiResponse::validation(
                    [$field => [__('messages.errors.duplicate')]],
                    __('messages.errors.validation'),
                    ErrorCode::Duplicate
                );
            }

            // RBAC denegado (middlewares permission:/role: de Spatie): 403 con el formato del proyecto,
            // 401 si ni siquiera hay sesión en el guard de la API.
            if ($e instanceof UnauthorizedException) {
                $logException($e, $request, false);

                if (auth('api')->guest()) {
                    return ApiResponse::unauthorized(__('messages.errors.unauthenticated'));
                }

                $required = array_merge($e->getRequiredRoles(), $e->getRequiredPermissions());

                try {
                    SecurityLogger::logPermissionDenied(
                        auth('api')->user(),
                        implode('|', $required),
                        $request->path(),
                        $request
                    );
                } catch (Throwable $logError) {
                    // El registro de seguridad no debe alterar la respuesta
                }

                $logApiRequest($e, $request);

                return ApiResponse::rfc7807(
                    __('messages.errors.forbidden'),
                    403,
                    __('messages.errors.forbidden'),
                    'forbidden',
                    $request->path(),
                    array_filter([
                        'required_permissions' => $e->getRequiredPermissions() ?: null,
                        'required_roles' => $e->getRequiredRoles() ?: null,
                    ])
                );
            }

            // HttpException genéricas
            if ($e instanceof HttpException) {
                $statusCode = $e->getStatusCode();
                $isCritical = $statusCode >= 500;
                $logException($e, $request, $isCritical);

                // Registrar en logs_api antes de devolver respuesta
                $logApiRequest($e, $request);

                return ApiResponse::rfc7807(
                    $e->getMessage() ?: __('messages.errors.generic'),
                    $statusCode,
                    $e->getMessage() ?: __('messages.errors.generic'),
                    $getErrorType($statusCode),
                    $request->path()
                );
            }

            // JWT inválido, expirado o revocado (401). Antes cada controller lo capturaba a mano.
            if ($e instanceof JWTException) {
                $logException($e, $request, false);

                return ApiResponse::unauthorized(
                    __('messages.errors.token_invalid'),
                    $e instanceof TokenExpiredException
                        ? ErrorCode::TokenExpired
                        : ErrorCode::TokenInvalid
                );
            }

            // AuthenticationException (401)
            if ($e instanceof AuthenticationException) {
                $logException($e, $request, false);

                return ApiResponse::unauthorized(__('messages.errors.unauthenticated'));
            }

            // AuthorizationException (403)
            if ($e instanceof AuthorizationException) {
                $logException($e, $request, false);

                return ApiResponse::forbidden(__('messages.errors.unauthorized'));
            }

            // ============================================
            // Errores Críticos (500+)
            // ============================================

            // Logging automático para errores críticos
            $logException($e, $request, true);

            // Respuesta RFC 7807 para errores críticos
            $message = config('app.debug')
                ? $e->getMessage()
                : __('messages.errors.server_error');

            return ApiResponse::rfc7807(
                $message,
                500,
                config('app.debug') ? $e->getMessage() : null,
                'internal_server_error',
                $request->path(),
                config('app.debug') ? [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => collect($e->getTrace())->map(function ($trace) {
                        return Arr::except($trace, ['args']);
                    })->all(),
                ] : []
            );
        });
    })->create();

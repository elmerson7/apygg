<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Http\Resources\Auth\AuthResource;
use App\Services\LogService;
use App\Services\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GoogleProvider;

/**
 * SocialAuthController
 *
 * Maneja autenticación social (Google, Facebook).
 * Soporta flujo web (redirect/callback) y deeplink móvil (Capacitor).
 */
class SocialAuthController
{
    protected array $allowedProviders = ['google', 'facebook'];

    public function __construct(
        protected SocialAuthService $socialAuthService
    ) {}

    /**
     * Redirige al proveedor OAuth (flujo web).
     * GET /auth/{provider}/redirect
     */
    public function redirect(string $provider): JsonResponse
    {
        if (! in_array($provider, $this->allowedProviders)) {
            return ApiResponse::error('Proveedor no soportado', 422);
        }

        /** @var GoogleProvider|FacebookProvider $driver */
        $driver = Socialite::driver($provider);

        $url = $driver
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return ApiResponse::success(['url' => $url], 'URL de autorización generada');
    }

    /**
     * Callback OAuth - intercambia code por tokens JWT.
     * GET /auth/{provider}/callback  (web)
     * POST /auth/{provider}/mobile   (deeplink Capacitor: envía el code)
     */
    public function callback(Request $request, string $provider): JsonResponse
    {
        if (! in_array($provider, $this->allowedProviders)) {
            return ApiResponse::error('Proveedor no soportado', 422);
        }

        try {
            /** @var GoogleProvider|FacebookProvider $driver */
            $driver = Socialite::driver($provider);
            $socialUser = $driver
                ->stateless()
                ->user();

            $user = $this->socialAuthService->findOrCreateUser($socialUser, $provider);
            $tokens = $this->socialAuthService->generateTokens($user);

            LogService::info('Login social exitoso', [
                'user_id' => $user->id,
                'provider' => $provider,
                'ip' => $request->ip(),
            ], 'security');

            return ApiResponse::success(
                new AuthResource([
                    'user' => $user,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_type' => 'bearer',
                ]),
                'Login social exitoso'
            );
        } catch (\Exception $e) {
            LogService::error('Error en login social', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('Error al autenticar con '.$provider, 500);
        }
    }

    /**
     * Exchange para deeplink móvil (Capacitor).
     * POST /auth/{provider}/mobile
     * Body: { "code": "...", "redirect_uri": "..." }
     */
    public function mobileCallback(Request $request, string $provider): JsonResponse
    {
        if (! in_array($provider, $this->allowedProviders)) {
            return ApiResponse::error('Proveedor no soportado', 422);
        }

        $request->validate([
            'code' => 'required|string',
            'redirect_uri' => 'required|url',
        ]);

        try {
            /** @var GoogleProvider|FacebookProvider $driver */
            $driver = Socialite::driver($provider);
            $socialUser = $driver
                ->stateless()
                ->redirectUrl($request->redirect_uri)
                /** @phpstan-ignore-next-line */
                ->userFromCode($request->code);

            $user = $this->socialAuthService->findOrCreateUser($socialUser, $provider);
            $tokens = $this->socialAuthService->generateTokens($user);

            LogService::info('Login social móvil exitoso', [
                'user_id' => $user->id,
                'provider' => $provider,
                'ip' => $request->ip(),
            ], 'security');

            return ApiResponse::success(
                new AuthResource([
                    'user' => $user,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_type' => 'bearer',
                ]),
                'Login social móvil exitoso'
            );
        } catch (\Exception $e) {
            LogService::error('Error en login social móvil', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('Error al autenticar con '.$provider, 500);
        }
    }
}

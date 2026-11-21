<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\AuthServiceInterface;
use App\Contracts\SocialLoginServiceInterface;
use App\Http\Controllers\Auth\Concerns\RespondsWithSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialLoginRequest;
use Illuminate\Http\JsonResponse;

/**
 * Login social (módulo `social-login`, docs/social-login.md): la app canjea lo que le dio el proveedor por los JWT de la API.
 */
class SocialLoginController extends Controller
{
    use RespondsWithSession;

    public function __construct(
        protected AuthServiceInterface $authService,
        private readonly SocialLoginServiceInterface $social,
    ) {}

    /**
     * Canjear el id_token (o access_token) de un proveedor por la sesión de la API
     *
     * Con 2FA activo responde el desafío (`two_factor_required`) igual que `POST /auth/login`.
     */
    public function store(SocialLoginRequest $request, string $provider): JsonResponse
    {
        $result = $this->social->exchange($provider, [
            'id_token' => $request->input('id_token'),
            'access_token' => $request->input('access_token'),
            'nonce' => $request->input('nonce'),
        ], $request->ip());

        return $this->sessionResponse($result);
    }
}

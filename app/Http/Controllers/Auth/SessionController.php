<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\SessionServiceInterface;
use App\Contracts\TokenServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

/**
 * Sesiones del usuario en curso (dispositivos con login abierto). Solo ve y revoca las suyas.
 */
class SessionController extends Controller
{
    public function __construct(
        protected SessionServiceInterface $sessions,
        protected TokenServiceInterface $tokens
    ) {}

    /**
     * Listar mis sesiones activas (la actual va marcada con `current`)
     */
    public function index(Request $request): JsonResponse
    {
        return $this->sendSuccess(
            $this->sessions->listFor($this->user($request), $this->currentSid()),
            'Sesiones obtenidas exitosamente'
        );
    }

    /**
     * Revocar una sesión mía
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->sessions->revoke($this->user($request), $id);

        return $this->sendSuccess(null, 'Sesión revocada');
    }

    /**
     * Revocar todas mis sesiones menos la actual
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $revocadas = $this->sessions->revokeOthers($this->user($request), $this->currentSid());

        return $this->sendSuccess(['revoked' => $revocadas], 'Sesiones revocadas');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('api');

        return $user;
    }

    private function currentSid(): ?string
    {
        try {
            $sid = $this->tokens->getTokenClaims()['sid'] ?? null;
        } catch (JWTException) {
            return null;
        }

        return is_string($sid) ? $sid : null;
    }
}

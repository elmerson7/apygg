<?php

namespace App\Http\Middleware;

use App\Contracts\ConsentServiceInterface;
use App\Exceptions\PrivacyException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige aceptar la versión vigente de los documentos legales obligatorios (docs/privacy.md). Apagado por defecto
 * (`privacy.consents.enforce`). Responde 409 `consent_required` con la lista `pending`. Nunca bloquea las rutas
 * de `privacy.consents.except` (auth, legal, consentimientos, derechos del usuario) ni a quien no está autenticado
 * (de eso se ocupa `auth:api`).
 */
class EnsureLegalConsents
{
    public function __construct(private readonly ConsentServiceInterface $consents) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('privacy.consents.enforce') || $request->isMethod('OPTIONS') || $request->is(...(array) config('privacy.consents.except', []))) {
            return $next($request);
        }

        try {
            $user = $request->user('api');
        } catch (\Throwable) {
            $user = null;
        }

        if (! $user instanceof User) {
            return $next($request);
        }

        $pending = $this->consents->pendingFor($user);

        if ($pending !== []) {
            throw PrivacyException::consentRequired($pending);
        }

        return $next($request);
    }
}

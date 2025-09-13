<?php

namespace App\Http\Controllers\Profiles;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfileController extends Controller
{
    /**
     * GET /profiles/me
     * Devuelve los datos del perfil del usuario autenticado
     */
    public function me(Request $request)
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'type'   => 'https://damblix.dev/errors/Unauthenticated',
                'title'  => 'Usuario no autenticado',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => 'Debes estar autenticado para acceder a este recurso.',
                'instance' => $request->fullUrl(),
                'meta' => [
                    'trace_id' => $request->attributes->get('trace_id'),
                    'timestamp' => now()->toISOString(),
                    'version' => '1.0',
                ],
            ], Response::HTTP_UNAUTHORIZED, [
                'Content-Type' => 'application/problem+json'
            ]);
        }

        return UserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}

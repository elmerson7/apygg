<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    /**
     * GET /users/me
     * Devuelve los datos del usuario autenticado
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

    /**
     * GET /users/{user}
     * Muestra un usuario específico
     */
    public function show(Request $request, User $user)
    {
        return UserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * PATCH /users/{user}
     * Actualiza un usuario específico
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        // Verificar que el usuario puede actualizar este perfil
        if ($request->user()->id !== $user->id) {
            return response()->json([
                'success' => false,
                'type'   => 'https://damblix.dev/errors/Forbidden',
                'title'  => 'Acceso denegado',
                'status' => Response::HTTP_FORBIDDEN,
                'detail' => 'No tienes permisos para actualizar este usuario.',
                'instance' => $request->fullUrl(),
                'meta' => [
                    'trace_id' => $request->attributes->get('trace_id'),
                    'timestamp' => now()->toISOString(),
                    'version' => '1.0',
                ],
            ], Response::HTTP_FORBIDDEN, [
                'Content-Type' => 'application/problem+json'
            ]);
        }

        $user->update($request->validated());

        return UserResource::make($user->fresh())
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}

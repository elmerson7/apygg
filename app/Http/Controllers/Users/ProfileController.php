<?php

namespace App\Http\Controllers\Users;

use App\Contracts\UserServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\UpdatePreferencesRequest;
use App\Http\Requests\Users\UpdateProfileRequest;
use App\Http\Resources\Users\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ProfileController
 *
 * Perfil y preferencias del usuario en sesión (prefijo /user).
 * Siempre opera sobre `$request->user()`: no recibe ids, así que nadie puede
 * tocar el perfil de otro por aquí.
 */
class ProfileController extends Controller
{
    public function __construct(protected UserServiceInterface $userService) {}

    /**
     * Ver el perfil propio.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->authUser($request)->load('profile');

        return $this->sendSuccess(new ProfileResource($user), 'Perfil obtenido exitosamente');
    }

    /**
     * Editar el perfil propio (solo campos de perfil).
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->userService->updateProfile($this->authUser($request), $request->validated());

        return $this->sendSuccess(new ProfileResource($user), 'Perfil actualizado exitosamente');
    }

    /**
     * Ver las preferencias propias.
     */
    public function showPreferences(Request $request): JsonResponse
    {
        return $this->sendSuccess($request->user()->preferences ?? new \stdClass, 'Preferencias obtenidas exitosamente');
    }

    /**
     * Actualizar las preferencias propias.
     */
    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $this->userService->updatePreferences((string) $this->authUser($request)->id, $request->validated());

        return $this->sendSuccess($user->preferences, 'Preferencias actualizadas exitosamente');
    }
}

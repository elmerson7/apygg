<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Concerns\RespondsWithApi;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;

/**
 * Base Controller
 *
 * Única base de los controladores de la aplicación (como la de Laravel).
 *
 * No trae acciones CRUD genéricas: cada controlador declara las suyas con
 * Form Requests tipados y route-model binding (o `findOrFail`). Una acción
 * heredada con firma fija impediría tiparlas: la firma de un hijo no puede
 * estrechar la del padre y PHP lanza un error fatal.
 *
 * Aporta autorización (`authorize()`), validación (`validate()`) y las
 * respuestas estándar de la API (`sendSuccess`, `sendError`, `sendPaginated`)
 * vía `RespondsWithApi`.
 */
abstract class Controller
{
    use AuthorizesRequests, ListsResources, RespondsWithApi, ValidatesRequests;

    /**
     * Usuario autenticado de la petición, tipado. Las rutas que lo usan van bajo `auth:api`, así que
     * un `null` aquí es un fallo de configuración de la ruta: responde 401 en vez de un error de PHP.
     */
    protected function authUser(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}

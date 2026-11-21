<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Concerns\RespondsWithApi;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

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
}

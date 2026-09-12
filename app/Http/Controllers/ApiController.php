<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithApi;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * ApiController
 *
 * Base para controladores que definen sus propias acciones.
 *
 * A diferencia de `Controller`, no trae andamiaje CRUD genérico. Eso importa:
 * los métodos concretos de `Controller` (`show(Request $request, string $id)`,
 * `update(Request $request, string $id)`…) fijan una firma, y sobrescribirlos
 * con route-model binding o con un Form Request tipado es un error fatal de PHP
 * —la firma de un hijo no puede estrechar la del padre—.
 *
 * Usa esta clase cuando quieras:
 *   - route-model binding: `show(MailAccount $mailAccount)`
 *   - Form Requests tipados: `store(StoreMailAccountRequest $request)`
 *   - acciones que no encajan en el CRUD
 *
 * Usa `Controller` cuando quieras el CRUD genérico con filtros, orden y
 * paginación resueltos por la base.
 *
 * Ambas comparten las respuestas estándar vía RespondsWithApi.
 */
abstract class ApiController
{
    use AuthorizesRequests, RespondsWithApi, ValidatesRequests;
}

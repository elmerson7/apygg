<?php

namespace App\OpenApi\Extensions;

use App\OpenApi\ApiErrorSchemas;
use Dedoc\Scramble\Support\ExceptionToResponseExtensions\HttpExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sustituye las respuestas de error por defecto de Scramble (que describen `{ message }`) por el
 * contrato real de la API: `ApiError` con `code` y `request_id` (docs/api-response-structure.md).
 *
 * Scramble da prioridad a la extensión registrada después, así que esta anula a las suyas.
 */
class ApiErrorExceptionToResponse extends HttpExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && $this->statusFor($type) !== null;
    }

    public function toResponse(Type $type): ?Response
    {
        $status = $this->statusFor($type);

        return $status === null ? null : ApiErrorSchemas::build($this->components, $status);
    }

    public function reference(ObjectType $type): ?Reference
    {
        $status = $this->statusFor($type);

        if ($status === null) {
            return null;
        }

        // El nombre sale del status: la misma respuesta se reutiliza en todas las operaciones.
        $name = ApiErrorSchemas::response($this->components, $status)->fullName;

        return new Reference('responses', $name, $this->components);
    }

    private function statusFor(ObjectType $type): ?int
    {
        return match (true) {
            $type->isInstanceOf(ValidationException::class) => 422,
            $type->isInstanceOf(AuthorizationException::class) => 403,
            $type->isInstanceOf(AuthenticationException::class) => 401,
            $type->isInstanceOf(RecordsNotFoundException::class) => 404,
            $type->isInstanceOf(HttpException::class) => $this->getResponseCode(
                count($type->templateTypes ?? []) > 3 ? ($type->templateTypes[7] ?? null) : ($type->templateTypes[0] ?? null),
                $type,
            ),
            default => null,
        };
    }
}

<?php

namespace App\OpenApi;

use App\Enums\ErrorCode;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Esquemas y respuestas comunes del contrato de la API (docs/api-response-structure.md).
 *
 * Todo error lleva `code` y `request_id`, venga del controller (`sendError`) o del handler
 * RFC 7807: por eso hay UN solo esquema de error (`ApiError`) y una respuesta reutilizable
 * por status, en vez de describir cada error en cada operación.
 */
final class ApiErrorSchemas
{
    public const ERROR = 'ApiError';

    public const ERROR_CODE = 'ErrorCode';

    public const PAGINATION_META = 'PaginationMeta';

    public const PAGINATION_LINKS = 'PaginationLinks';

    public const CURSOR_META = 'CursorPaginationMeta';

    public const CURSOR_LINKS = 'CursorPaginationLinks';

    /** Respuestas de error reutilizables: status => nombre del componente y descripción. */
    private const RESPONSES = [
        400 => ['BadRequest', 'Solicitud malformada.'],
        401 => ['Unauthenticated', 'Sin sesión, credenciales incorrectas o token expirado/inválido (`code`: `unauthenticated`, `token_expired`, `token_invalid`, `api_key_required`, `api_key_invalid`...).'],
        403 => ['Forbidden', 'Sin permiso, rol o policy para la acción (`code`: `forbidden`, `insufficient_scope`, `feature_disabled`...).'],
        404 => ['NotFound', 'El recurso o la ruta no existe (`code`: `not_found`).'],
        405 => ['MethodNotAllowed', 'Método HTTP no admitido en la ruta.'],
        409 => ['Conflict', 'La acción choca con el estado actual del recurso.'],
        422 => ['ValidationFailed', 'Datos inválidos: `errors` indica los mensajes por campo (`code`: `validation_failed`).'],
        429 => ['RateLimited', 'Límite de peticiones superado; mira la cabecera `Retry-After` (`code`: `rate_limited`).'],
    ];

    /**
     * Respuesta de error (referencia a `components.responses`) para un status; las registra al vuelo.
     */
    public static function response(Components $components, int $status): Reference
    {
        [$name, $description] = self::RESPONSES[$status] ?? ["Error{$status}", "Error HTTP {$status}."];

        $reference = new Reference('responses', $name, $components);

        if (! $components->has($reference)) {
            $components->add($reference, self::build($components, $status, $description));
        }

        return $reference;
    }

    /** Respuesta de error inline (para respuestas que sustituyen a una ya existente). */
    public static function build(Components $components, int $status, ?string $description = null): Response
    {
        return Response::make($status)
            ->setDescription($description ?? (self::RESPONSES[$status][1] ?? "Error HTTP {$status}."))
            ->setContent('application/json', Schema::fromType(self::ref($components, self::ERROR)));
    }

    /** Referencia a un esquema común, registrándolo si falta. */
    public static function ref(Components $components, string $name): Reference
    {
        if (! $components->hasSchema($name)) {
            $components->addSchema($name, Schema::fromType(self::definition($name)));
        }

        return $components->getSchemaReference($name);
    }

    private static function definition(string $name): ObjectType|StringType
    {
        return match ($name) {
            self::ERROR_CODE => (new StringType)
                ->enum(array_map(fn (ErrorCode $c) => $c->value, ErrorCode::cases()))
                ->setDescription('Códigos de error del boilerplate. Un proyecto puede añadir los suyos (string), por eso `ApiError.code` es un string abierto.'),
            self::PAGINATION_META => self::paginationMeta(),
            self::PAGINATION_LINKS => self::paginationLinks(),
            self::CURSOR_META => self::cursorMeta(),
            self::CURSOR_LINKS => self::cursorLinks(),
            default => self::error(),
        };
    }

    private static function error(): ObjectType
    {
        $object = new ObjectType;
        $object->setDescription('Error de la API. Los clientes ramifican por `code` (estable), no por `message` (cambia con el idioma). Según quién lo produce (controller o handler RFC 7807) puede traer además `success`, `message`, `type`, `title`, `status`, `detail`, `instance`, `errors` y `meta`.');

        $object
            ->addProperty('success', (new BooleanType)->const(false))
            ->addProperty('message', new StringType)
            ->addProperty('code', (new StringType)->setDescription('Código estable legible por máquina (snake_case). Ver `ErrorCode`.')->examples(['validation_failed']))
            ->addProperty('request_id', (new StringType)->setDescription('Mismo valor que la cabecera `X-Request-ID`; pídelo al reportar un fallo.'))
            ->addProperty('type', new StringType)
            ->addProperty('title', new StringType)
            ->addProperty('status', new IntegerType)
            ->addProperty('detail', new StringType)
            ->addProperty('instance', new StringType)
            ->addProperty('errors', self::described(new ObjectType, 'Solo en validación: mensajes por campo.')
                ->additionalProperties((new ArrayType)->setItems(new StringType)))
            ->addProperty('meta', self::described(new ObjectType, 'Solo en errores del handler: `version`, `timestamp`, `request_id`.'));

        return $object->setRequired(['code', 'request_id']);
    }

    private static function described(ObjectType $object, string $description): ObjectType
    {
        $object->setDescription($description);

        return $object;
    }

    private static function paginationMeta(): ObjectType
    {
        $object = new ObjectType;
        $object->setDescription('Paginación por página (`page` + `per_page`). Un endpoint puede añadir campos propios.');

        return $object
            ->addProperty('current_page', new IntegerType)
            ->addProperty('per_page', new IntegerType)
            ->addProperty('total', new IntegerType)
            ->addProperty('last_page', new IntegerType)
            ->addProperty('from', (new IntegerType)->nullable(true))
            ->addProperty('to', (new IntegerType)->nullable(true))
            ->setRequired(['current_page', 'per_page', 'total', 'last_page', 'from', 'to']);
    }

    private static function cursorMeta(): ObjectType
    {
        $object = new ObjectType;
        $object->setDescription('Paginación por cursor (`cursor` + `per_page`): sin totales. Los cursores son opacos; se reenvían tal cual en `?cursor=`.');

        return $object
            ->addProperty('per_page', new IntegerType)
            ->addProperty('has_more', new BooleanType)
            ->addProperty('next_cursor', (new StringType)->nullable(true))
            ->addProperty('prev_cursor', (new StringType)->nullable(true))
            ->setRequired(['per_page', 'has_more', 'next_cursor', 'prev_cursor']);
    }

    private static function cursorLinks(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('next', (new StringType)->nullable(true))
            ->addProperty('prev', (new StringType)->nullable(true))
            ->setRequired(['next', 'prev']);
    }

    private static function paginationLinks(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('first', (new StringType)->nullable(true))
            ->addProperty('last', (new StringType)->nullable(true))
            ->addProperty('prev', (new StringType)->nullable(true))
            ->addProperty('next', (new StringType)->nullable(true))
            ->setRequired(['first', 'last', 'prev', 'next']);
    }
}

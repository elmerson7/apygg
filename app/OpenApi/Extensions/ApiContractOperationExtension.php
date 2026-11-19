<?php

namespace App\OpenApi\Extensions;

use App\OpenApi\ApiErrorSchemas;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NullType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\ObjectType as InferredObjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Hace que cada operación describa el contrato real de la API (docs/api-response-structure.md)
 * sin anotar controller por controller:
 *
 * - Seguridad por operación, según el middleware de la ruta (`auth:api` → JWT, `auth:api-key` → API Key).
 * - Respuestas de error comunes (401/403/404/429) con el esquema `ApiError`.
 * - Envelope de éxito (`success`, `message`, `data`) y de listados (`data[]` + `meta` + `links`).
 *   Scramble no puede inferir el contenido de `sendPaginated()`: los ítems salen del `$resource`
 *   que declara el controller.
 */
class ApiContractOperationExtension extends OperationExtension
{
    public const BEARER = 'bearerAuth';

    public const API_KEY = 'apiKeyAuth';

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $middleware = array_values(array_filter($routeInfo->route->gatherMiddleware(), 'is_string'));

        $this->documentSecurity($operation, $middleware);
        $this->documentErrors($operation, $routeInfo, $middleware);

        $responses = [];
        foreach ($operation->responses ?? [] as $response) {
            $responses[] = $response instanceof Response
                ? $this->normalizeResponse($operation, $response, $routeInfo)
                : $response;
        }

        $operation->responses = $this->sortedUniqueByStatus($responses);
    }

    /**
     * @param  list<string>  $middleware
     */
    private function documentSecurity(Operation $operation, array $middleware): void
    {
        // `security: []` explícito = ruta pública; si hay auth se añaden los requisitos.
        $operation->security = [];

        $schemes = [];
        foreach ($middleware as $name) {
            if ($name === 'auth:api-key') {
                $schemes[self::API_KEY] = true;
            } elseif ($name === 'auth' || str_starts_with($name, 'auth:')) {
                $schemes[self::BEARER] = true;
            }
        }

        foreach (array_keys($schemes) as $scheme) {
            $operation->addSecurity(new SecurityRequirement([$scheme => []]));
        }

        // `auth.optional`: funciona sin sesión y, si llega un Bearer, la usa.
        if (in_array('auth.optional', $middleware, true)) {
            $operation->addSecurity(new SecurityRequirement([]));
            $operation->addSecurity(new SecurityRequirement([self::BEARER => []]));
        }
    }

    /**
     * @param  list<string>  $middleware
     */
    private function documentErrors(Operation $operation, RouteInfo $routeInfo, array $middleware): void
    {
        $components = $this->openApiTransformer->getComponents();
        $wanted = [429];

        $authorizes = collect($middleware)->contains(
            fn (string $m) => Str::startsWith($m, ['permission:', 'role:', 'role_or_permission:', 'api-key-scope:', 'feature:', 'ip.whitelist', 'can:'])
        );
        if ($authorizes) {
            $wanted[] = 403;
        }

        if ($routeInfo->route->parameterNames() !== []) {
            $wanted[] = 404;
        }

        foreach ($wanted as $status) {
            if (! $this->hasStatus($operation, $status)) {
                $operation->addResponse(ApiErrorSchemas::response($components, $status));
            }
        }
    }

    private function hasStatus(Operation $operation, int $status): bool
    {
        foreach ($operation->responses ?? [] as $response) {
            $resolved = $response instanceof Reference ? $response->resolve() : $response;

            if ($resolved instanceof Response && (int) $resolved->code === $status) {
                return true;
            }
        }

        return false;
    }

    private function normalizeResponse(Operation $operation, Response $response, RouteInfo $routeInfo): Response|Reference
    {
        $status = (int) $response->code;

        if ($response->description === '') {
            $response->setDescription(match ($status) {
                201 => 'Creado',
                204 => 'Sin contenido',
                default => $status < 300 ? 'Correcto' : 'Error',
            });
        }

        $schema = $response->content['application/json'] ?? null;
        $type = $schema instanceof Schema ? $schema->type : null;

        if (! $type instanceof ObjectType) {
            return $response;
        }

        $isEnvelope = $type->hasProperty('success');

        if ($status >= 400) {
            // Errores armados con `sendError()`: la misma respuesta reutilizable que el resto.
            return $isEnvelope || $type->hasProperty('code')
                ? ApiErrorSchemas::response($this->openApiTransformer->getComponents(), $status)
                : $response;
        }

        if (! $isEnvelope) {
            return $response;
        }

        $paginated = $type->hasProperty('links') && $type->hasProperty('meta');
        $links = $paginated ? $type->getProperty('links') : null;
        // `sendCursorPaginated` no tiene `first`/`last` en `links`: es la paginación por cursor.
        $cursor = $links instanceof ObjectType && ! $links->hasProperty('first');

        if ($paginated) {
            $this->documentPagination($operation, $cursor);
        }

        $response->setContent('application/json', Schema::fromType(
            $paginated ? $this->paginatedEnvelope($routeInfo, $cursor) : $this->successEnvelope($type, $routeInfo)
        ));

        return $response;
    }

    /**
     * Listados: `page` y `per_page` documentados igual en todos (Scramble los infiere como string).
     */
    private function documentPagination(Operation $operation, bool $cursor): void
    {
        $default = 15;
        foreach ($operation->parameters as $parameter) {
            if ($parameter instanceof Parameter && $parameter->name === 'per_page' && is_numeric($parameter->schema?->type->default)) {
                $default = (int) $parameter->schema->type->default;
            }
        }

        $operation->parameters = array_values(array_filter(
            $operation->parameters,
            fn ($p) => ! ($p instanceof Parameter && in_array($p->name, ['page', 'cursor', 'per_page'], true) && $p->in === 'query')
        ));

        $page = (new IntegerType)->default(1);
        $page->setMin(1);
        $position = $cursor
            ? ['cursor', new StringType, 'Cursor opaco de `meta.next_cursor`/`meta.prev_cursor`; sin él, primera página.']
            : ['page', $page, 'Número de página (empieza en 1).'];
        $perPage = (new IntegerType)->default($default);
        $perPage->setMin(1);
        $perPage->setMax(100);

        foreach ([
            $position,
            ['per_page', $perPage, 'Ítems por página; se acota al máximo del endpoint (100 por defecto).'],
        ] as [$name, $type, $description]) {
            $parameter = Parameter::make($name, 'query');
            $parameter->description = $description;
            $parameter->schema = Schema::fromType($type);
            $operation->parameters[] = $parameter;
        }
    }

    /**
     * `success` + `message` + `data` (cuando el controller manda datos).
     */
    private function successEnvelope(ObjectType $inferred, RouteInfo $routeInfo): ObjectType
    {
        $envelope = new ObjectType;
        $envelope->addProperty('success', (new BooleanType)->const(true));
        $required = ['success'];

        if ($inferred->hasProperty('message')) {
            $envelope->addProperty('message', new StringType);
            $required[] = 'message';
        }

        // `sendSuccess(null)` omite `data`; Scramble lo infiere como `null`.
        if ($inferred->hasProperty('data') && ! $inferred->getProperty('data') instanceof NullType) {
            $envelope->addProperty('data', $this->wrappedInResource($inferred->getProperty('data'), $routeInfo));
            $required[] = 'data';
        }

        return $envelope->setRequired($required);
    }

    /**
     * `sendSuccess($model)` envuelve el modelo en el `$resource` del controller en tiempo de ejecución
     * (`wrapData`), pero Scramble ve el modelo. Si `data` es un modelo y el controller declara su
     * Resource, el contrato describe el Resource, que es lo que sale por el cable.
     */
    private function wrappedInResource(mixed $data, RouteInfo $routeInfo): mixed
    {
        if (! $data instanceof Reference || ! $this->isModelSchema($data->fullName)) {
            return $data;
        }

        $resource = $this->controllerResource($routeInfo->className());

        return $resource === null ? $data : $this->itemType($routeInfo);
    }

    /**
     * Scramble nombra el esquema de un modelo con su nombre corto (`Settings`): se busca en `app/Models`.
     */
    private function isModelSchema(string $name): bool
    {
        foreach (glob(app_path('Models/{,*/}'.$name.'.php'), GLOB_BRACE) ?: [] as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path()) + 1));

            if (is_a($class, Model::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `data[]` + `meta` + `links`, con los ítems del `$resource` del controller si lo declara.
     */
    private function paginatedEnvelope(RouteInfo $routeInfo, bool $cursor): ObjectType
    {
        $components = $this->openApiTransformer->getComponents();

        $envelope = new ObjectType;
        $envelope->addProperty('success', (new BooleanType)->const(true));
        $envelope->addProperty('data', (new ArrayType)->setItems($this->itemType($routeInfo)));
        $envelope->addProperty('meta', ApiErrorSchemas::ref($components, $cursor ? ApiErrorSchemas::CURSOR_META : ApiErrorSchemas::PAGINATION_META));
        $envelope->addProperty('links', ApiErrorSchemas::ref($components, $cursor ? ApiErrorSchemas::CURSOR_LINKS : ApiErrorSchemas::PAGINATION_LINKS));

        return $envelope->setRequired(['success', 'data', 'meta', 'links']);
    }

    private function itemType(RouteInfo $routeInfo): ObjectType|Reference
    {
        $resource = $this->controllerResource($routeInfo->className());

        if ($resource === null) {
            return new ObjectType;
        }

        $schema = $this->openApiTransformer->transform(new InferredObjectType($resource));

        return $schema instanceof Reference || $schema instanceof ObjectType ? $schema : new ObjectType;
    }

    /**
     * @return class-string<JsonResource>|null
     */
    private function controllerResource(?string $controller): ?string
    {
        if ($controller === null || ! class_exists($controller)) {
            return null;
        }

        $class = new ReflectionClass($controller);

        if (! $class->hasProperty('resource')) {
            return null;
        }

        $resource = $class->getProperty('resource')->getDefaultValue();

        return is_string($resource) && is_a($resource, JsonResource::class, true) ? $resource : null;
    }

    /**
     * Ordena por status y descarta repetidos (p. ej. un 401 de `sendError` junto al de la ruta autenticada).
     *
     * @param  array<int, Response|Reference>  $responses
     * @return list<Response|Reference>
     */
    private function sortedUniqueByStatus(array $responses): array
    {
        $status = function (Response|Reference $r): int {
            $resolved = $r instanceof Reference ? $r->resolve() : $r;

            return $resolved instanceof Response ? (int) $resolved->code : 0;
        };

        usort($responses, fn ($a, $b) => $status($a) <=> $status($b));

        $unique = [];
        foreach ($responses as $response) {
            $unique[$status($response)] ??= $response;
        }

        return array_values($unique);
    }
}

<?php

namespace App\OpenApi\Extensions;

use App\OpenApi\ApiErrorSchemas;
use App\Support\Listing\Listing;
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
        $this->documentListing($operation, $routeInfo);

        $responses = [];
        foreach ($operation->responses ?? [] as $response) {
            $responses[] = $response instanceof Response
                ? $this->normalizeResponse($operation, $response, $routeInfo)
                : $response;
        }

        $operation->responses = $this->sortedUniqueByStatus($responses);
    }

    /**
     * Listados con `#[Listing]`: documenta `filter[campo]`, `sort` e `include` con la lista blanca real del endpoint
     * (la misma que aplica `ListingQuery`), más el 400 `invalid_query`.
     */
    private function documentListing(Operation $operation, RouteInfo $routeInfo): void
    {
        $attribute = $routeInfo->reflectionMethod()?->getAttributes(Listing::class)[0] ?? null;
        if ($attribute === null) {
            return;
        }

        $spec = $attribute->newInstance();
        $params = [];

        foreach ($spec->filters as $name => $definition) {
            [$type] = $spec->filterDefinition($name);
            $schema = match ($type) {
                'bool' => new BooleanType,
                'int' => new IntegerType,
                'from', 'to' => (new StringType)->format('date-time'),
                default => new StringType,
            };
            $description = match ($type) {
                'exact', 'int' => 'Igualdad; varios valores separados por comas (`a,b`) = cualquiera de ellos.',
                'partial' => 'Contiene el texto (sin distinguir mayúsculas).',
                'from' => 'Desde (inclusive), fecha ISO 8601.',
                'to' => 'Hasta (inclusive), fecha ISO 8601.',
                'bool' => '`true` o `false`.',
                default => 'Filtro del endpoint.',
            };
            $params['filter['.$name.']'] = [$schema, $description];
        }

        // Alias de listados anteriores (`?role=x` = `filter[role]=x`): se mantienen documentados (el contrato no los quita).
        $existing = array_map(fn ($p) => $p instanceof Parameter && $p->in === 'query' ? $p->name : null, $operation->parameters);
        foreach ($spec->legacy as $name) {
            // Si el Form Request ya lo documenta (con su enum, formato...), se respeta tal cual.
            if (isset($spec->filters[$name]) && ! in_array($name, $existing, true)) {
                $params[$name] = [$params['filter['.$name.']'][0], 'Alias de `filter['.$name.']` (se mantiene por compatibilidad).'];
            }
        }

        $sorts = array_keys($spec->sortColumns());
        if ($sorts !== [] && $spec->legacy !== []) {
            $params['sort_by'] = [new StringType, 'Alias de `sort` (compatibilidad): un solo campo.'];
            $params['order'] = [new StringType, 'Alias (compatibilidad): `asc` o `desc` para un único criterio de `sort`.'];
            $params['sort_order'] = [new StringType, 'Alias de `order` (compatibilidad).'];
        }

        if ($sorts !== []) {
            $params['sort'] = [(new StringType)->default($spec->defaultSort), 'Orden: campos separados por comas, `-` delante = descendente (`'.$spec->defaultSort.'` por defecto). Permitidos: '.implode(', ', $sorts).'.'];
        }

        if ($spec->includes !== []) {
            $params['include'] = [new StringType, 'Relaciones a incluir, separadas por comas. Permitidas: '.implode(', ', $spec->includes).'.'];
        }

        // Un parámetro que el contrato ya publicaba conserva su valor por defecto documentado (solo documentación:
        // el comportamiento real sigue siendo el de `#[Listing]`).
        $defaults = [];
        foreach ($operation->parameters as $p) {
            if ($p instanceof Parameter && $p->in === 'query' && isset($params[$p->name]) && $p->schema?->type->default !== null) {
                $defaults[$p->name] = $p->schema->type->default;
            }
        }

        $operation->parameters = array_values(array_filter(
            $operation->parameters,
            fn ($p) => ! ($p instanceof Parameter && $p->in === 'query' && isset($params[$p->name]))
        ));

        foreach ($params as $name => [$type, $description]) {
            if (isset($defaults[$name])) {
                $type->default($defaults[$name]);
            }

            $parameter = Parameter::make($name, 'query');
            $parameter->description = $description;
            $parameter->schema = Schema::fromType($type);
            $operation->parameters[] = $parameter;
        }

        if (! $this->hasStatus($operation, 400)) {
            $operation->addResponse(ApiErrorSchemas::response($this->openApiTransformer->getComponents(), 400));
        }

        $this->ensurePaginatedResponse($operation);
    }

    /**
     * `ListsResources::listing()` elige entre `sendPaginated` y `sendCursorPaginated` en ejecución y Scramble no
     * puede inferir el 200 de esa mezcla: se declara el envelope de listado (por páginas; el cursor se documenta
     * en `description`) y `normalizeResponse` lo completa con el `$resource` del controller.
     */
    private function ensurePaginatedResponse(Operation $operation): void
    {
        foreach ($operation->responses ?? [] as $response) {
            $resolved = $response instanceof Reference ? $response->resolve() : $response;
            if ($resolved instanceof Response && (int) $resolved->code === 200) {
                $schema = $resolved->content['application/json'] ?? null;
                $type = $schema instanceof Schema ? $schema->type : null;
                if ($type instanceof ObjectType && $type->hasProperty('links')) {
                    return;
                }
            }
        }

        $envelope = (new ObjectType)
            ->addProperty('success', new BooleanType)
            ->addProperty('data', new ArrayType)
            ->addProperty('meta', new ObjectType)
            ->addProperty('links', (new ObjectType)->addProperty('first', new StringType));

        $operation->responses = array_values(array_filter(
            $operation->responses ?? [],
            fn ($r) => ! (($r instanceof Reference ? $r->resolve() : $r) instanceof Response && (int) ($r instanceof Reference ? $r->resolve() : $r)->code === 200)
        ));
        $operation->addResponse(
            Response::make(200)
                ->setDescription('Correcto. Con `?cursor=` o `?pagination=cursor` la paginación es por cursor (`meta.next_cursor`, sin totales).')
                ->setContent('application/json', Schema::fromType($envelope))
        );
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

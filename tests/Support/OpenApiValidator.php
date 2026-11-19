<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Valida respuestas REALES de la API contra `docs/openapi.json` (el contrato versionado).
 *
 * Por qué opis/json-schema y no `league/openapi-psr7-validator`: el documento es OpenAPI 3.1 (así lo
 * emite Scramble) y su esquema es JSON Schema 2020-12 (`type: ["string","null"]`, `const`, `examples`).
 * El validador de The League se apoya en cebe/php-openapi y valida con la semántica de OpenAPI 3.0
 * (`nullable`): no entiende 3.1. Opis soporta 2020-12 de forma nativa y está mantenido; lo único
 * que se escribe aquí es localizar la operación y la respuesta (unas 60 líneas).
 *
 * La operación se identifica por la plantilla de la ruta que atendió la petición
 * (`users/{id}`), así que no hay que casar URLs a mano.
 */
final class OpenApiValidator
{
    private static ?object $spec = null;

    /**
     * @return list<string> Problemas encontrados; vacío si la respuesta cumple el contrato.
     */
    public static function problems(TestResponse $response, ?string $method = null, ?string $route = null): array
    {
        $spec = self::spec();
        $method = strtolower($method ?? request()->method());
        $route = '/'.ltrim($route ?? (string) request()->route()?->uri(), '/');
        $route = (string) preg_replace('/\{(\w+)\?\}/', '{$1}', $route);

        $operation = $spec->paths->{$route}->{$method} ?? null;
        if ($operation === null) {
            return ["{$method} {$route} no existe en docs/openapi.json (¿ruta nueva sin `make openapi`?)"];
        }

        $status = (string) $response->getStatusCode();
        $declared = $operation->responses->{$status} ?? $operation->responses->default ?? null;
        if ($declared === null) {
            return ["{$method} {$route} responde {$status}, que el contrato no documenta (documentados: ".implode(', ', array_keys((array) $operation->responses)).')'];
        }

        $declared = self::resolve($spec, $declared);
        $media = $declared->content->{'application/json'} ?? null;
        $body = $response->getContent();

        if ($media === null) {
            return $body === '' || $body === false
                ? []
                : ["{$method} {$route} {$status}: el contrato no declara cuerpo JSON y la respuesta trae uno"];
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return ["{$method} {$route} {$status}: Content-Type no es JSON (".$response->headers->get('Content-Type').')'];
        }

        $schema = (object) [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'allOf' => [self::resolve($spec, $media->schema ?? (object) [])],
            'components' => $spec->components, // destino de los `$ref: #/components/...`
        ];

        $result = (new Validator)->validate(json_decode((string) $body), $schema);

        if ($result->isValid()) {
            return [];
        }

        $errors = (new ErrorFormatter)->format($result->error(), false);

        return array_map(
            fn (string $path, string|array $messages) => "{$method} {$route} {$status} {$path}: ".implode('; ', (array) $messages),
            array_keys($errors),
            $errors,
        );
    }

    /** Sigue un `$ref` de primer nivel (p. ej. `#/components/responses/Unauthenticated`). */
    private static function resolve(object $spec, object $node): object
    {
        while (isset($node->{'$ref'}) && str_starts_with($node->{'$ref'}, '#/')) {
            $target = $spec;
            foreach (explode('/', substr($node->{'$ref'}, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
                $target = $target->{$segment} ?? throw new RuntimeException("`\$ref` roto: {$node->{'$ref'}}");
            }
            $node = $target;
        }

        return $node;
    }

    private static function spec(): object
    {
        if (self::$spec === null) {
            $path = base_path('docs/openapi.json');
            if (! is_file($path)) {
                throw new RuntimeException('Falta docs/openapi.json. Genéralo con: make openapi');
            }

            self::$spec = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        }

        return self::$spec;
    }
}

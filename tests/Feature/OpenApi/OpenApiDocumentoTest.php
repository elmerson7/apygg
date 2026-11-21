<?php

use App\OpenApi\OpenApiDocument;
use App\Support\ApiPath;

/*
 * docs/openapi.json es el contrato que consumen los fronts y la suite Contract. Si alguien cambia
 * rutas, requests o resources sin regenerarlo, los tipos de los clientes mienten: este test lo impide.
 */

test('docs/openapi.json versionado coincide con lo que genera Scramble', function () {
    $versionado = is_file(OpenApiDocument::path()) ? (string) file_get_contents(OpenApiDocument::path()) : '';

    $mensaje = 'docs/openapi.json está desactualizado respecto a las rutas/requests/resources actuales. Ejecuta: make openapi';

    if (ApiPath::prefix() === '') {
        expect($versionado)->toBe(OpenApiDocument::render(), $mensaje);

        return;
    }

    // Con API_PREFIX el contrato (paths, esquemas) es el mismo; solo cambian los `servers`, que dependen del prefijo.
    $versionadoSpec = json_decode($versionado, false, 512, JSON_THROW_ON_ERROR);
    unset($versionadoSpec->servers);

    foreach ($versionadoSpec->paths as $item) {
        unset($item->servers);
    }

    expect(OpenApiDocument::encode((array) $versionadoSpec))->toBe(OpenApiDocument::encode(OpenApiDocument::withoutServers()), $mensaje);
});

test('los paths del contrato nunca llevan el prefijo de versión y los servers sí', function () {
    config(['api.prefix' => '/v1/']);

    $spec = OpenApiDocument::spec();

    expect(array_keys($spec['paths']))->not->toContain('/v1/users')
        ->and($spec['paths'])->toHaveKey('/users')
        ->and($spec['servers'][0]['url'])->toEndWith('/v1')
        // La salud no lleva prefijo: su path declara el servidor raíz.
        ->and($spec['paths']['/health']['servers'][0]['url'])->not->toEndWith('/v1');
});

test('el documento OpenAPI es 3.1, tiene seguridad Bearer y API Key y no filtra rutas internas', function () {
    $spec = json_decode((string) file_get_contents(OpenApiDocument::path()), true, 512, JSON_THROW_ON_ERROR);

    expect($spec['openapi'])->toStartWith('3.1')
        ->and($spec['components']['securitySchemes'])->toHaveKeys(['bearerAuth', 'apiKeyAuth'])
        ->and($spec['components']['schemas'])->toHaveKeys(['ApiError', 'PaginationMeta', 'PaginationLinks']);

    foreach (array_keys($spec['paths']) as $path) {
        expect($path)->not->toStartWith('/test/')
            ->not->toStartWith('/backoffice')
            ->not->toStartWith('/livewire')
            ->not->toStartWith('/horizon')
            ->not->toStartWith('/telescope');
    }
});

test('cada operación tiene operationId único y todos los $ref internos existen', function () {
    $spec = json_decode((string) file_get_contents(OpenApiDocument::path()), true, 512, JSON_THROW_ON_ERROR);

    $ids = [];
    foreach ($spec['paths'] as $operations) {
        foreach ($operations as $operation) {
            $ids[] = $operation['operationId'];
        }
    }
    expect($ids)->toBe(array_values(array_unique($ids)));

    $refs = [];
    array_walk_recursive($spec, function ($value, $key) use (&$refs) {
        if ($key === '$ref') {
            $refs[] = $value;
        }
    });

    foreach (array_unique($refs) as $ref) {
        $node = $spec;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            expect($node)->toHaveKey($segment);
            $node = $node[$segment];
        }
    }
});

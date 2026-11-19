<?php

use App\OpenApi\OpenApiDocument;

/*
 * docs/openapi.json es el contrato que consumen los fronts y la suite Contract. Si alguien cambia
 * rutas, requests o resources sin regenerarlo, los tipos de los clientes mienten: este test lo impide.
 */

test('docs/openapi.json versionado coincide con lo que genera Scramble', function () {
    $versionado = is_file(OpenApiDocument::path()) ? (string) file_get_contents(OpenApiDocument::path()) : '';

    expect($versionado)->toBe(
        OpenApiDocument::render(),
        'docs/openapi.json está desactualizado respecto a las rutas/requests/resources actuales. Ejecuta: make openapi'
    );
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

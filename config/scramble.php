<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
     * Prefijo de la API. Vacío: las rutas cuelgan de la raíz (no existe /api). No lo pongas a `null`:
     * Scramble lo toma como `api` y recorta ese prefijo de las rutas (`/api-keys` saldría como `/-keys`).
     * Qué rutas entran al documento lo decide App\OpenApi\ApiDocs::includesRoute().
     */
    'api_path' => '',

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => 'docs/openapi.json',

    /*
     * URL del servidor que lleva el documento versionado (`make openapi`). Fija a propósito: no es
     * APP_URL, que cambia entre dev, CI y contenedores y ensuciaría el diff. Los clientes generados
     * reciben su base URL por configuración, no de aquí. La UI viva (/docs/api) sí usa `servers`.
     */
    'export_server_url' => env('OPENAPI_SERVER_URL', 'http://localhost:8000'),

    'info' => [
        /*
         * API version.
         */
        'version' => config('app.version', '1.0.0'),

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         */
        'description' => <<<'MD'
            API REST. Cada respuesta usa un envelope estable (detalle en `docs/api-response-structure.md`).

            **Éxito**: `{ "success": true, "message": "...", "data": ... }`. Los listados paginados devuelven
            `data` (array), `meta` (`current_page`, `per_page`, `total`, `last_page`, `from`, `to`) y `links`;
            se piden con `?page=` y `?per_page=` (con máximo por endpoint).

            **Errores**: toda respuesta 4xx/5xx lleva `code` (estable, ramifica por él, no por `message`) y
            `request_id` (igual que la cabecera `X-Request-ID`). La validación (422) añade `errors` por campo.

            **Autenticación**: JWT (`Authorization: Bearer`) obtenido en `POST /auth/login`, o API Key
            (`X-API-Key`) en las rutas de integración. Las rutas sin candado son públicas.
            MD,
    ],

    /*
     * Customize Stoplight Elements UI
     */
    'ui' => [
        /*
         * Define the title of the documentation's website. App name is used when this config is `null`.
         */
        'title' => config('app.title').' Documentation',

        /*
         * Define the theme of the documentation. Available options are `light`, `dark`, and `system`.
         */
        'theme' => 'system',

        /*
         * Hide the `Try It` feature. Enabled by default.
         */
        'hide_try_it' => false,

        /*
         * Hide the schemas in the Table of Contents. Enabled by default.
         */
        'hide_schemas' => false,

        /*
         * URL to an image that displays as a small square logo next to the title, above the table of contents.
         */
        'logo' => '',

        /*
         * Use to fetch the credential policy for the Try It feature. Options are: omit, include (default), and same-origin
         */
        'try_it_credentials_policy' => 'include',

        /*
         * There are three layouts for Elements:
         * - sidebar - (Elements default) Three-column design with a sidebar that can be resized.
         * - responsive - Like sidebar, except at small screen sizes it collapses the sidebar into a drawer that can be toggled open.
         * - stacked - Everything in a single column, making integrations with existing websites that have their own sidebar or other columns already.
         */
        'layout' => 'responsive',
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => [
        'Local' => env('APP_URL', 'http://localhost:8010'),
    ],

    /**
     * Determines how Scramble stores the descriptions of enum cases.
     * Available options:
     * - 'description' – Case descriptions are stored as the enum schema's description using table formatting.
     * - 'extension' – Case descriptions are stored in the `x-enumDescriptions` enum schema extension.
     *
     *    @see https://redocly.com/docs-legacy/api-reference-docs/specification-extensions/x-enum-descriptions
     * - false - Case descriptions are ignored.
     */
    'enum_cases_description_strategy' => 'description',

    /**
     * Determines how Scramble stores the names of enum cases.
     * Available options:
     * - 'names' – Case names are stored in the `x-enumNames` enum schema extension.
     * - 'varnames' - Case names are stored in the `x-enum-varnames` enum schema extension.
     * - false - Case names are not stored.
     */
    'enum_cases_names_strategy' => false,

    /**
     * When Scramble encounters deep objects in query parameters, it flattens the parameters so the generated
     * OpenAPI document correctly describes the API. Flattening deep query parameters is relevant until
     * OpenAPI 3.2 is released and query string structure can be described properly.
     *
     * For example, this nested validation rule describes the object with `bar` property:
     * `['foo.bar' => ['required', 'int']]`.
     *
     * When `flatten_deep_query_parameters` is `true`, Scramble will document the parameter like so:
     * `{"name":"foo[bar]", "schema":{"type":"int"}, "required":true}`.
     *
     * When `flatten_deep_query_parameters` is `false`, Scramble will document the parameter like so:
     *  `{"name":"foo", "schema": {"type":"object", "properties":{"bar":{"type": "int"}}, "required": ["bar"]}, "required":true}`.
     */
    'flatten_deep_query_parameters' => true,

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [],

    /*
    |--------------------------------------------------------------------------
    | Documentación Pública
    |--------------------------------------------------------------------------
    |
    | Permite consultar la documentación sin autenticarse. Pensado para
    | desarrollo local; el Gate 'viewApiDocs' lo ignora en producción.
    |
    | Sin esto, el acceso exige un usuario administrador.
    |
    */

    'public_docs' => env('SCRAMBLE_PUBLIC_DOCS', false),

];

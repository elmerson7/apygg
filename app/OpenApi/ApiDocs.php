<?php

namespace App\OpenApi;

use App\OpenApi\Extensions\ApiContractOperationExtension;
use App\OpenApi\Extensions\ApiErrorExceptionToResponse;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;

/**
 * Configura Scramble para el contrato de la API (docs/api-contract.md).
 *
 * Lo llama `AppServiceProvider`. Aquí vive todo lo que no es config declarativa: qué rutas
 * entran al documento, los esquemas de seguridad, y las extensiones que describen el envelope
 * y los errores en un solo sitio en vez de anotar cada controller.
 */
final class ApiDocs
{
    public static function register(): void
    {
        Scramble::configure()
            ->routes(self::includesRoute(...))
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->info->title = (string) config('app.title');

                $openApi->components->addSecurityScheme(
                    ApiContractOperationExtension::BEARER,
                    SecurityScheme::http('bearer', 'JWT')
                        ->as(ApiContractOperationExtension::BEARER)
                        ->setDescription('JWT de `POST /auth/login`. Cabecera `Authorization: Bearer <access_token>`.')
                );
                $openApi->components->addSecurityScheme(
                    ApiContractOperationExtension::API_KEY,
                    SecurityScheme::apiKey('header', 'X-API-Key')
                        ->as(ApiContractOperationExtension::API_KEY)
                        ->setDescription('API Key de integración (docs/api-keys.md). Cabecera `X-API-Key`.')
                );

                // Los esquemas comunes salen siempre, los use o no alguna operación: son parte del contrato.
                foreach ([ApiErrorSchemas::ERROR, ApiErrorSchemas::ERROR_CODE, ApiErrorSchemas::PAGINATION_META, ApiErrorSchemas::PAGINATION_LINKS, ApiErrorSchemas::CURSOR_META, ApiErrorSchemas::CURSOR_LINKS] as $schema) {
                    ApiErrorSchemas::ref($openApi->components, $schema);
                }
            })
            ->withOperationTransformers(ApiContractOperationExtension::class);

        Scramble::registerExtension(ApiErrorExceptionToResponse::class);
    }

    /**
     * Qué rutas son API pública. Entran solo las de controllers de la aplicación: así quedan
     * fuera, sin listas que mantener, las de paquetes (Livewire, Filament/backoffice, Horizon,
     * Telescope, Resend, Broadcasting), las closures (`/`, `docs/api`, `storage`) y las de
     * diagnóstico (`test/*`, solo local/testing).
     */
    public static function includesRoute(Route $route): bool
    {
        $action = $route->getAction('uses');

        // Las rutas declaradas tras `Route::middleware(...)` traen el FQCN con `\` inicial.
        if (! is_string($action) || ! str_starts_with(ltrim($action, '\\'), 'App\\Http\\Controllers\\')) {
            return false;
        }

        return ! str_starts_with($route->uri(), 'test/');
    }
}

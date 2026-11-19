<?php

namespace App\OpenApi;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorResult;
use Dedoc\Scramble\Scramble;

/**
 * El documento OpenAPI versionado (`docs/openapi.json`).
 *
 * Lo comparten `php artisan openapi:export` (que lo escribe) y el test que comprueba que el
 * archivo versionado coincide con lo que genera Scramble. Es determinista: el servidor sale de
 * `scramble.export_server_url` (no de APP_URL, que cambia entre dev, CI y contenedores) y el JSON
 * se serializa siempre igual para que los diffs en git sean legibles.
 */
final class OpenApiDocument
{
    public static function path(): string
    {
        return base_path((string) config('scramble.export_path', 'docs/openapi.json'));
    }

    public static function generate(): GeneratorResult
    {
        return app(Generator::class)->generate(
            Scramble::getGeneratorConfig(Scramble::DEFAULT_API)
                ->cloneWithoutExposing()
                ->useConfig([...config('scramble'), 'servers' => ['Local' => config('scramble.export_server_url')]])
        );
    }

    /**
     * @param  array<mixed, mixed>  $spec
     */
    public static function encode(array $spec): string
    {
        return json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public static function render(): string
    {
        return self::encode(self::generate()->spec());
    }
}

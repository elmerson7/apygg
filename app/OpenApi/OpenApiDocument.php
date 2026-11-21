<?php

namespace App\OpenApi;

use App\Support\ApiPath;
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

    /**
     * La especificación lista para versionar. Los paths NUNCA llevan el prefijo de versión (los tipos de los
     * fronts no cambian con `API_PREFIX`): con prefijo, `servers[].url` termina en `/v1` y las rutas que nunca
     * lo llevan (salud, webhooks...) declaran su propio `servers` a nivel de path.
     *
     * @return array<mixed, mixed>
     */
    public static function spec(): array
    {
        $spec = self::generate()->spec();
        $prefix = ApiPath::prefix();

        if ($prefix === '') {
            return $spec;
        }

        $root = rtrim((string) config('scramble.export_server_url'), '/');
        $servers = array_map(fn ($server) => [...(array) $server, 'url' => $root], array_values((array) ($spec['servers'] ?? [['url' => $root]])));
        $paths = [];

        foreach ((array) ($spec['paths'] ?? []) as $path => $item) {
            $path = (string) $path;

            if ($path === '/'.$prefix || str_starts_with($path, '/'.$prefix.'/')) {
                $paths['/'.ltrim(substr($path, strlen($prefix) + 1), '/')] = $item;
            } else {
                $item['servers'] = $servers;
                $paths[$path] = $item;
            }
        }

        $spec['paths'] = $paths;
        $spec['servers'] = array_map(fn ($server) => [...$server, 'url' => $root.'/'.$prefix], $servers);

        return $spec;
    }

    /**
     * La especificación sin ningún `servers` (raíz ni por path): es lo que no depende de `API_PREFIX`.
     *
     * @return array<mixed, mixed>
     */
    public static function withoutServers(): array
    {
        $spec = self::spec();
        unset($spec['servers']);

        foreach ((array) ($spec['paths'] ?? []) as $path => $item) {
            unset($spec['paths'][$path]['servers']);
        }

        return $spec;
    }

    public static function render(): string
    {
        return self::encode(self::spec());
    }
}

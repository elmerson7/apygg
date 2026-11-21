<?php

use App\Services\CacheService;

/*
 * El boilerplate es una base de la que nacen proyectos con otro nombre: el literal `apygg` no puede quedar
 * en el código de `app/` ni en `config/` (claves de caché, prefijos de API keys, User-Agent, correos de
 * ejemplo...). El nombre sale de `app.slug` (APP_SLUG, o APP_NAME). Excepciones: ninguna a propósito.
 */

/** @return list<string> */
function archivosPhpDe(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));

    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

test('el literal apygg no aparece en app/ ni en config/', function () {
    $excepciones = []; // ruta relativa => motivo (vacía: nada justifica el literal)
    $hallazgos = [];

    foreach ([...archivosPhpDe('app'), ...archivosPhpDe('config')] as $path) {
        $rel = ltrim(substr($path, strlen(base_path())), '/');

        if (isset($excepciones[$rel])) {
            continue;
        }

        foreach (file($path) as $i => $linea) {
            if (stripos($linea, 'apygg') !== false) {
                $hallazgos[] = $rel.':'.($i + 1);
            }
        }
    }

    expect($hallazgos)->toBe([], 'Literal del boilerplate: usa config(\'app.slug\') o env. '.implode(', ', $hallazgos));
});

test('los prefijos de claves, API keys y el User-Agent salen del slug de la instalación', function () {
    expect(config('api-keys.prefixes.live'))->toEndWith('_live_')
        ->and(config('api-keys.prefixes.test'))->toEndWith('_test_')
        ->and(config('webhooks.user_agent'))->toContain((string) config('app.slug'))
        ->and(CacheService::prefix())->toBe(config('app.slug'));
});

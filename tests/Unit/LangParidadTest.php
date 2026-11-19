<?php

/**
 * Paridad de claves entre idiomas: si a un idioma le falta una clave (o sobra), el front y los
 * correos saldrían a medias. Cada idioma de `config('i18n.locales')` debe tener los mismos archivos y claves.
 */
function langKeys(string $locale): array
{
    $keys = [];

    foreach (glob(lang_path($locale.'/*.php')) as $file) {
        $flatten = function (array $items, string $prefix) use (&$flatten, &$keys): void {
            foreach ($items as $key => $value) {
                is_array($value) ? $flatten($value, "$prefix$key.") : $keys[] = "$prefix$key";
            }
        };
        $flatten(require $file, basename($file, '.php').'.');
    }

    sort($keys);

    return $keys;
}

test('cada idioma soportado tiene su carpeta en lang', function () {
    foreach (config('i18n.locales') as $locale) {
        expect(is_dir(lang_path($locale)))->toBeTrue("Falta lang/$locale");
    }
});

test('todos los idiomas tienen exactamente las mismas claves que el idioma por defecto', function () {
    $base = langKeys(config('i18n.default_locale'));

    expect($base)->not->toBeEmpty();

    foreach (config('i18n.locales') as $locale) {
        $keys = langKeys($locale);

        expect(array_values(array_diff($base, $keys)))->toBe([], "A lang/$locale le faltan claves")
            ->and(array_values(array_diff($keys, $base)))->toBe([], "A lang/$locale le sobran claves");
    }
});

test('los marcadores de reemplazo coinciden entre idiomas', function () {
    $placeholders = fn (string $text): array => (function () use ($text) {
        preg_match_all('/:[a-z_]+/i', $text, $m);
        sort($m[0]);

        return $m[0];
    })();

    $flat = function (string $locale): array {
        $out = [];
        foreach (glob(lang_path($locale.'/*.php')) as $file) {
            $walk = function (array $items, string $prefix) use (&$walk, &$out): void {
                foreach ($items as $k => $v) {
                    is_array($v) ? $walk($v, "$prefix$k.") : $out["$prefix$k"] = (string) $v;
                }
            };
            $walk(require $file, basename($file, '.php').'.');
        }

        return $out;
    };

    $es = $flat('es');
    $en = $flat('en');

    foreach (['messages', 'auth', 'passwords'] as $file) {
        foreach ($es as $key => $text) {
            if (str_starts_with($key, $file.'.') && isset($en[$key])) {
                expect($placeholders($text))->toBe($placeholders($en[$key]), "Marcadores distintos en $key");
            }
        }
    }
});

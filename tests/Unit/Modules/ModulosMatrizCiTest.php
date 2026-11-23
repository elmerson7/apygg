<?php

use Symfony\Component\Yaml\Yaml;

/*
 * La CI quita cada módulo opcional por separado (job modules-removed de .github/workflows/ci.yml).
 * Si se añade un módulo opcional sin su escenario, o con el orden de quitado mal, este test falla.
 */

/**
 * @return array<string, list<string>>
 */
function escenariosSinModulos(): array
{
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $escenarios = [];

    foreach ($ci['jobs']['modules-removed']['strategy']['matrix']['include'] as $fila) {
        $escenarios[$fila['escenario']] = preg_split('/\s+/', trim($fila['modulos'])) ?: [];
    }

    return $escenarios;
}

/**
 * @return array<string, list<string>> módulo opcional => sus dependencias
 */
function modulosOpcionales(): array
{
    $opcionales = [];

    foreach (glob(base_path('modules/*.php')) ?: [] as $archivo) {
        $manifiesto = require $archivo;
        if (($manifiesto['core'] ?? true) === false) {
            $opcionales[basename($archivo, '.php')] = array_values($manifiesto['depends'] ?? []);
        }
    }

    ksort($opcionales);

    return $opcionales;
}

test('cada módulo opcional tiene su escenario en la matriz de la CI', function () {
    $escenarios = escenariosSinModulos();

    foreach (array_keys(modulosOpcionales()) as $modulo) {
        expect($escenarios)->toHaveKey($modulo)
            ->and($escenarios[$modulo])->toContain($modulo);
    }
});

test('el escenario «todos» quita todos los módulos opcionales y ninguno del núcleo', function () {
    $todos = escenariosSinModulos()['todos'];

    expect($todos)->toContain(...array_keys(modulosOpcionales()));

    // Lo que no es opcional o ya no existe (la propia CI lo quitó en este escenario), pero nunca del núcleo.
    foreach ($todos as $modulo) {
        $archivo = base_path("modules/{$modulo}.php");
        if (is_file($archivo)) {
            expect((require $archivo)['core'] ?? true)->toBeFalse("«todos» no debe quitar el módulo del núcleo {$modulo}");
        }
    }
});

test('cada escenario quita a los dependientes antes que el módulo del que dependen', function () {
    $opcionales = modulosOpcionales();

    foreach (escenariosSinModulos() as $escenario => $lista) {
        foreach ($lista as $posicion => $modulo) {
            // Todo módulo opcional que dependa de este y siga instalado debe haberse quitado antes.
            foreach ($opcionales as $otro => $dependencias) {
                if (in_array($modulo, $dependencias, true)) {
                    $antes = array_search($otro, $lista, true);
                    expect($antes)->not->toBeFalse("«{$escenario}»: falta quitar {$otro} (depende de {$modulo})")
                        ->and($antes)->toBeLessThan($posicion, "«{$escenario}»: {$otro} debe quitarse antes que {$modulo}");
                }
            }
        }
    }
});

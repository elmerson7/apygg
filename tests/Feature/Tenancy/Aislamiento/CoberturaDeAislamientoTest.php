<?php

use App\Traits\BelongsToTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

/*
| Red de seguridad: un modelo nuevo con `BelongsToTenant` (o una tabla nueva con `tenant_id`) OBLIGA a añadir su
| prueba de aislamiento. Plantilla: tests/Support/AssertsTenantIsolation.php; guía: docs/multi-tenancy.md
| §«Cómo probar el aislamiento de un recurso nuevo».
*/

/** Modelo con BelongsToTenant => archivo de tests/Feature/Tenancy/Aislamiento/ que lo cubre. */
const AISLAMIENTO_MODELOS_CUBIERTOS = [
    'App\Models\File' => 'ArchivosAislamientoTest.php',
    'App\Models\Webhook' => 'WebhooksAislamientoTest.php',
    'App\Models\WebhookDelivery' => 'WebhooksAislamientoTest.php',
    'App\Models\ApiKey' => 'ApiKeysAislamientoTest.php',
    'App\Models\TenantInvitation' => 'InvitacionesAislamientoTest.php',
];

/**
 * Tablas con `tenant_id` que NO son un modelo con BelongsToTenant, y dónde se prueban.
 * Si añades una tabla con `tenant_id`, lista aquí su prueba.
 */
const AISLAMIENTO_TABLAS_CUBIERTAS = [
    'logs_activity' => 'LogsAislamientoTest.php',
    'logs_api' => 'LogsAislamientoTest.php',
    'logs_security' => 'LogsAislamientoTest.php',
    'sequences' => 'SecuenciasAislamientoTest.php',
    'idempotency_keys' => 'IdempotenciaAislamientoTest.php',
    'tenant_user' => 'MiembrosAislamientoTest.php',
    'feature_flag_overrides' => 'FeatureFlagsYPlanAislamientoTest.php',
    'tenant_limit_overrides' => 'FeatureFlagsYPlanAislamientoTest.php',
    // Tablas de Spatie con equipos (docs/permissions.md §Teams): cubiertas por SpatieTeamsTest
    'model_has_roles' => '../SpatieTeamsTest.php',
    'model_has_permissions' => '../SpatieTeamsTest.php',
    'roles' => '../SpatieTeamsTest.php',
];

function aislamientoDir(): string
{
    return base_path('tests/Feature/Tenancy/Aislamiento');
}

/** @return list<class-string> */
function modelosConTenant(): array
{
    $encontrados = [];

    foreach ((new Finder)->files()->in(app_path('Models'))->name('*.php') as $file) {
        $clase = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($clase) && in_array(BelongsToTenant::class, class_uses_recursive($clase), true)) {
            $encontrados[] = $clase;
        }
    }

    sort($encontrados);

    return $encontrados;
}

test('todo modelo con BelongsToTenant tiene su prueba de aislamiento registrada', function () {
    $sinCubrir = array_values(array_diff(modelosConTenant(), array_keys(AISLAMIENTO_MODELOS_CUBIERTOS)));

    expect($sinCubrir)->toBe([], 'Modelos con BelongsToTenant sin prueba de aislamiento: '.implode(', ', $sinCubrir)
        .'. Crea tests/Feature/Tenancy/Aislamiento/<Recurso>AislamientoTest.php (plantilla: tests/Support/AssertsTenantIsolation.php) y añádelo a AISLAMIENTO_MODELOS_CUBIERTOS.');
});

test('la lista de modelos cubiertos no tiene modelos que ya no usan BelongsToTenant', function () {
    $sobran = array_values(array_diff(array_keys(AISLAMIENTO_MODELOS_CUBIERTOS), modelosConTenant()));

    expect($sobran)->toBe([]);
});

test('cada modelo cubierto tiene su archivo de prueba y lo menciona', function (string $modelo, string $archivo) {
    $ruta = aislamientoDir().'/'.$archivo;
    $corto = class_basename($modelo);

    expect(file_exists($ruta))->toBeTrue("Falta {$archivo}");
    expect(file_get_contents($ruta))->toContain($corto);
})->with(fn () => array_map(null, array_keys(AISLAMIENTO_MODELOS_CUBIERTOS), array_values(AISLAMIENTO_MODELOS_CUBIERTOS)));

test('toda tabla con tenant_id está cubierta por un modelo con prueba o por la lista de tablas', function () {
    $tablasDeModelos = array_map(fn (string $m) => (new $m)->getTable(), array_keys(AISLAMIENTO_MODELOS_CUBIERTOS));
    $conocidas = array_merge($tablasDeModelos, array_keys(AISLAMIENTO_TABLAS_CUBIERTAS), ['tenant_notes' /* tabla de prueba */]);

    $conTenant = collect(Schema::getTables())
        ->pluck('name')
        ->filter(fn (string $tabla) => Schema::hasColumn($tabla, 'tenant_id'))
        ->values()
        ->all();

    $sinCubrir = array_values(array_diff($conTenant, $conocidas));

    expect($sinCubrir)->toBe([], 'Tablas con tenant_id sin prueba de aislamiento: '.implode(', ', $sinCubrir));
});

test('cada tabla cubierta por la lista apunta a un archivo de prueba que existe', function (string $tabla, string $archivo) {
    expect(file_exists(aislamientoDir().'/'.$archivo))->toBeTrue("{$tabla}: falta {$archivo}");
})->with(fn () => array_map(null, array_keys(AISLAMIENTO_TABLAS_CUBIERTAS), array_values(AISLAMIENTO_TABLAS_CUBIERTAS)));

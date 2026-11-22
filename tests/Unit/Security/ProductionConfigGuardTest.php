<?php

use App\Support\Security\ProductionConfigGuard;
use Illuminate\Config\Repository;

function configSegura(array $override = []): Repository
{
    return new Repository(array_replace_recursive([
        'app' => ['debug' => false, 'production_guard' => true],
        'jwt' => ['algo' => 'HS256', 'secret' => str_repeat('k', 64)],
        'database' => [
            'default' => 'pgsql',
            'connections' => ['pgsql' => ['password' => 'Zx9-clave-larga-y-unica']],
            'redis' => ['default' => ['password' => 'otra-clave-unica-123']],
        ],
        'scout' => ['driver' => 'meilisearch', 'meilisearch' => ['key' => 'clave-meili-propia-123']],
        'sentry' => ['dsn' => 'https://x@sentry.example/1', 'traces_sample_rate' => 0.05, 'profiles_sample_rate' => 0.01],
    ], $override));
}

test('una configuración de producción correcta no tiene problemas', function () {
    expect(ProductionConfigGuard::problems(configSegura()))->toBe([]);
    ProductionConfigGuard::assertSafe(configSegura(), 'production');
});

test('rechaza APP_DEBUG activo en producción', function () {
    expect(fn () => ProductionConfigGuard::assertSafe(configSegura(['app' => ['debug' => true]]), 'production'))
        ->toThrow(RuntimeException::class, 'APP_DEBUG');
});

test('rechaza claves por defecto o débiles', function (array $override, string $texto) {
    expect(implode('|', ProductionConfigGuard::problems(configSegura($override))))->toContain($texto);
})->with([
    'jwt vacío' => [['jwt' => ['secret' => '']], 'JWT_SECRET'],
    'jwt corto' => [['jwt' => ['secret' => 'secret']], 'JWT_SECRET'],
    'bd por defecto' => [['database' => ['connections' => ['pgsql' => ['password' => 'secret']]]], 'DB_PASSWORD'],
    'redis de compose' => [['database' => ['redis' => ['default' => ['password' => 'RedisSecure2024!']]]], 'REDIS_PASSWORD'],
    'meili masterKey' => [['scout' => ['meilisearch' => ['key' => 'masterKey']]], 'MEILISEARCH_KEY'],
]);

test('rechaza Sentry al 100 % solo cuando hay DSN', function () {
    $alto = ['sentry' => ['traces_sample_rate' => 1.0, 'profiles_sample_rate' => 1.0]];

    expect(ProductionConfigGuard::problems(configSegura($alto)))->toHaveCount(2)
        ->and(ProductionConfigGuard::problems(configSegura(['sentry' => $alto['sentry'] + ['dsn' => '']])))->toBe([]);
});

test('no actúa fuera de producción, con el guard apagado ni en comandos de construcción', function () {
    $mala = configSegura(['app' => ['debug' => true]]);

    ProductionConfigGuard::assertSafe($mala, 'local');
    ProductionConfigGuard::assertSafe(configSegura(['app' => ['debug' => true, 'production_guard' => false]]), 'production');
    ProductionConfigGuard::assertSafe($mala, 'production', 'package:discover');
    ProductionConfigGuard::assertSafe($mala, 'production', 'clear-compiled');
    ProductionConfigGuard::assertSafe($mala, 'production', ProductionConfigGuard::EXTERNAL_TOOL);
    expect(fn () => ProductionConfigGuard::assertSafe($mala, 'production', 'octane:start'))->toThrow(RuntimeException::class);
});

test('la plantilla .env.production.example cumple su propia validación', function () {
    $env = file_get_contents(base_path('.env.production.example'));

    expect($env)->toContain('APP_DEBUG=false')->toContain('LOG_LEVEL=warning')
        ->and($env)->not->toMatch('/^(JWT_SECRET|DB_PASSWORD|MEILISEARCH_KEY)=\S/m')
        ->and($env)->toMatch('/^SENTRY_TRACES_SAMPLE_RATE=0\.\d+$/m');
});

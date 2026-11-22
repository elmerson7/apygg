<?php

use App\Contracts\MaintenanceServiceInterface; // module:maintenance
use App\Support\ApiPath;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * Prefijo de versión opcional (`API_PREFIX`, docs/api-routes-conventions.md). Con prefijo vacío todo cuelga de
 * la raíz como siempre; con `v1` las rutas de la app pasan a `/v1/...` y las de infraestructura siguen en la raíz.
 */

/**
 * Registra routes/api.php en un router limpio con el prefijo dado y devuelve las URIs.
 *
 * @return list<string>
 */
function urisConPrefijo(string $prefijo): array
{
    config(['api.prefix' => $prefijo]);

    $router = new Router(app('events'), app());
    Route::swap($router);
    require base_path('routes/api.php');

    return collect($router->getRoutes()->getRoutes())->map->uri()->all();
}

test('normaliza el prefijo: v1, /v1, v1/ y /v1/ son v1', function (string $crudo) {
    config(['api.prefix' => $crudo]);

    expect(ApiPath::prefix())->toBe('v1');
})->with(['v1', '/v1', 'v1/', '/v1/', ' v1 ']);

test('un prefijo vacío o solo barras equivale a sin prefijo', function (string $crudo) {
    config(['api.prefix' => $crudo]);

    expect(ApiPath::prefix())->toBe('')
        ->and(ApiPath::with('/users'))->toBe('/users')
        ->and(ApiPath::strip('users'))->toBe('users');
})->with(['', '/', '  ']);

test('con prefijo vacío las rutas cuelgan de la raíz como siempre', function () {
    $uris = urisConPrefijo('');

    expect($uris)->toContain('users', 'health', 'webhooks/inbound/{provider}', 'auth/login')
        ->and(collect($uris)->filter(fn ($u) => str_starts_with($u, 'v1')))->toBeEmpty();
});

test('con prefijo las rutas de la app cuelgan de /v1 y las de infraestructura siguen en la raíz', function () {
    $uris = urisConPrefijo('v1');

    expect($uris)->toContain('v1/broadcasting/auth'); // module:reverb
    expect($uris)->toContain('v1/users', 'v1/auth/login')
        ->not->toContain('users')
        ->not->toContain('auth/login')
        // Fuera del prefijo: salud, raíz y webhooks entrantes (los llaman servidores y proveedores).
        ->toContain('/', 'health', 'health/live', 'health/ready', 'health/detailed', 'webhooks/inbound/{provider}', 'webhooks/mail')
        ->not->toContain('v1/health')
        ->not->toContain('v1/webhooks/inbound/{provider}');
});

test('toda ruta de la API está o prefijada o en la lista de excluidas del prefijo', function () {
    $uris = urisConPrefijo('v1');

    $sueltas = collect($uris)
        ->reject(fn (string $uri) => str_starts_with($uri, 'v1/') || ApiPath::isUnprefixed($uri))
        ->values()
        ->all();

    expect($sueltas)->toBe([]);
});

test('las rutas prefijadas responden y la misma ruta sin prefijo da 404', function () {
    // Se registran las rutas con prefijo (como lo haría el arranque con API_PREFIX=v1)...
    config(['api.prefix' => 'v1']);
    app('router')->setRoutes(new RouteCollection);
    Route::middleware('api')->group(base_path('routes/api.php'));
    app('router')->getRoutes()->refreshNameLookups();

    // ...y se vacía el prefijo para que TestCase no lo antepondrá a las URLs de la petición.
    config(['api.prefix' => '']);

    $this->getJson('/v1/auth/me')->assertUnauthorized();
    $this->getJson('/auth/me')->assertNotFound();
    $this->getJson('/health')->assertOk();
    $this->getJson('/v1/health')->assertNotFound();
});

test('los patrones de excepción valen con y sin prefijo', function () {
    $peticion = fn (string $uri) => Request::create($uri);

    config(['api.prefix' => '']);
    expect(ApiPath::is($peticion('/auth/login'), 'auth/*'))->toBeTrue()
        ->and(ApiPath::is($peticion('/'), '/'))->toBeTrue()
        ->and(ApiPath::is($peticion('/users'), 'auth/*'))->toBeFalse();

    config(['api.prefix' => 'v1']);
    expect(ApiPath::is($peticion('/v1/auth/login'), 'auth/*'))->toBeTrue()
        ->and(ApiPath::is($peticion('/v1/users'), 'auth/*'))->toBeFalse()
        ->and(ApiPath::is($peticion('/health/live'), 'health/*'))->toBeTrue()
        ->and(ApiPath::path($peticion('/v1/user/profile')))->toBe('user/profile')
        // Un segmento que solo empieza igual no es el prefijo.
        ->and(ApiPath::strip('v10/users'))->toBe('v10/users');
});

// module:maintenance:start
test('el mantenimiento deja pasar la salud con prefijo', function () {
    config(['api.prefix' => 'v1']);

    $permitida = app(MaintenanceServiceInterface::class)->allows(Request::create('/health/live'));
    $prefijada = app(MaintenanceServiceInterface::class)->allows(Request::create('/v1/health/live'));

    expect($permitida)->toBeTrue()
        ->and($prefijada)->toBeTrue();
});
// module:maintenance:end

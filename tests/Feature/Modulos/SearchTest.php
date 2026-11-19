<?php

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Endpoint GET /search: validación y envelope. La autorización por modelo y la
 * lista blanca de filtros ya están en tests/Feature/Authorization/SearchAccessTest.php;
 * aquí se cubren el 401, los límites de la petición y el formato de respuesta.
 * Scout usa el driver null en los tests: la búsqueda no devuelve datos reales.
 */

uses(RefreshDatabase::class);

function busquedaCabeceras(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function busquedaUsuarioCon(string ...$permisos): User
{
    $user = User::factory()->create();

    foreach ($permisos as $permiso) {
        $user->permissions()->attach(Permission::where('name', $permiso)->value('id'));
    }

    return $user;
}

test('buscar sin token responde 401', function () {
    $this->getJson('/search?q=ab')->assertUnauthorized();
});

// BUG: sin Meilisearch, AppServiceProvider (línea 220) cambia Scout al driver `database`;
// su DatabaseEngine llama a User::toSearchableArray() (app/Models/User.php:271) sobre filas
// con created_at nulo y la búsqueda responde 500 (SearchController.php:81).
test('quien tiene users.read recibe el envelope de búsqueda completada', function () {
    $this->withHeaders(busquedaCabeceras(busquedaUsuarioCon('users.read')))->getJson('/search?q=ana')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Búsqueda completada exitosamente');
});

test('buscar exige users.read y no basta users.update', function () {
    $this->withHeaders(busquedaCabeceras(busquedaUsuarioCon('users.update')))->getJson('/search?q=ana')
        ->assertForbidden();
});

test('el término de búsqueda debe tener al menos dos caracteres', function () {
    $this->withHeaders(busquedaCabeceras(busquedaUsuarioCon('users.read')))->getJson('/search?q=a')
        ->assertUnprocessable();
});

test('el término de búsqueda no puede superar 255 caracteres', function () {
    $this->withHeaders(busquedaCabeceras(busquedaUsuarioCon('users.read')))
        ->getJson('/search?q='.str_repeat('a', 256))
        ->assertUnprocessable();
});

test('buscar en un modelo que no existe responde 422', function () {
    $this->withHeaders(busquedaCabeceras(busquedaUsuarioCon('users.read')))->getJson('/search?q=ana&models[]=roles')
        ->assertUnprocessable();
});

test('el número de resultados por página no puede superar 100', function () {
    $this->withHeaders(busquedaCabeceras(busquedaUsuarioCon('users.read')))->getJson('/search?q=ana&per_page=101')
        ->assertUnprocessable();
});

test('la página de búsqueda debe ser al menos la primera', function () {
    $this->withHeaders(busquedaCabeceras(busquedaUsuarioCon('users.read')))->getJson('/search?q=ana&page=0')
        ->assertUnprocessable();
});

// BUG: mismo fallo que el test anterior (driver database de Scout sin Meilisearch).
test('un administrador busca en usuarios sin recibir errores de permisos', function () {
    $this->withHeaders(busquedaCabeceras(User::factory()->admin()->create()))->getJson('/search?q=ana&models[]=users')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('sin Meilisearch la búsqueda usa la base de datos y encuentra al usuario por nombre', function () {
    config(['scout.driver' => 'database']);
    $buscado = User::factory()->create(['name' => 'Anabela Zuritaxq']);
    User::factory()->create(['name' => 'Otro Nombre']);

    $respuesta = $this->withHeaders(busquedaCabeceras(User::factory()->admin()->create()))
        ->getJson('/search?q=Zuritaxq&models[]=users')
        ->assertOk();

    expect(json_encode($respuesta->json('data')))->toContain((string) $buscado->id)
        ->and(json_encode($respuesta->json('data')))->toContain('Anabela Zuritaxq');
});

<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Permisos declarados en las rutas con permission:/role_or_permission:
 * (por ruta, con su guard explícito).
 *
 * @return array<int, array{uri: string, permissions: array<int, string>, guard: string|null}>
 */
function routePermissions(): array
{
    $found = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! preg_match('/^(?:permission|role_or_permission):(.+)$/', $middleware, $m)) {
                continue;
            }

            $parts = explode(',', $m[1]);

            $found[] = [
                'uri' => $route->methods()[0].' '.$route->uri(),
                'permissions' => explode('|', $parts[0]),
                'guard' => $parts[1] ?? null,
            ];
        }
    }

    return $found;
}

/**
 * Permisos citados como literal en el código (policies, controllers, servicios, requests...).
 *
 * @return array<int, string>
 */
function codePermissions(): array
{
    $names = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/(?:->can|checkPermissionTo|hasPermissionTo)\('([a-z_]+\.[a-z_-]+)'/", file_get_contents($file->getPathname()), $m);
        $names = array_merge($names, $m[1]);
    }

    return array_values(array_unique($names));
}

/**
 * Permisos de los ítems del menú sembrado.
 *
 * @return array<int, string>
 */
function menuPermissions(): array
{
    preg_match_all("/'permission' => '([a-z_]+\.[a-z_-]+)'/", file_get_contents(database_path('seeders/MenuSeeder.php')), $m);

    return array_values(array_unique($m[1]));
}

/** Todo el texto de app/ y de las rutas, para comprobar que un permiso sembrado se usa en algún sitio. */
function codeCorpus(): string
{
    $corpus = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $corpus .= file_get_contents($file->getPathname());
        }
    }

    foreach (glob(base_path('routes/api/*.php')) as $file) {
        $corpus .= file_get_contents($file);
    }

    return $corpus.file_get_contents(database_path('seeders/MenuSeeder.php'));
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

test('todo permiso que usan las rutas está sembrado', function () {
    $seeded = Permission::pluck('name')->all();
    $used = collect(routePermissions())->pluck('permissions')->flatten()->unique()->all();

    expect($used)->not->toBeEmpty()
        ->and(array_diff($used, $seeded))->toBeEmpty();
});

test('todo permiso que consultan policies, controllers y servicios está sembrado', function () {
    $seeded = Permission::pluck('name')->all();

    expect(codePermissions())->not->toBeEmpty()
        ->and(array_diff(codePermissions(), $seeded))->toBeEmpty();
});

test('todo permiso de los ítems del menú está sembrado', function () {
    $seeded = Permission::pluck('name')->all();

    expect(menuPermissions())->not->toBeEmpty()
        ->and(array_diff(menuPermissions(), $seeded))->toBeEmpty();
});

test('no se siembra ningún permiso que el código no use', function () {
    $corpus = codeCorpus();

    $unused = Permission::pluck('name')->filter(
        fn (string $name) => ! str_contains($corpus, "'{$name}'") && ! str_contains($corpus, "permission:{$name}")
    )->values()->all();

    expect($unused)->toBeEmpty();
});

test('el catálogo del seeder coincide con los permisos de la BD', function () {
    $catalog = collect(PermissionSeeder::catalog())->pluck('name')->sort()->values()->all();

    expect(Permission::pluck('name')->sort()->values()->all())->toBe($catalog);
});

test('toda ruta con permission: declara el permiso exacto y el guard api', function () {
    foreach (routePermissions() as $route) {
        foreach ($route['permissions'] as $permission) {
            $this->assertStringContainsString('.', $permission, "{$route['uri']} usa un permiso sin acción ({$permission})");
        }
        $this->assertSame('api', $route['guard'], "{$route['uri']} debe declarar el guard api");
    }
});

test('los seeders son idempotentes', function () {
    $permissions = Permission::count();
    $roles = Role::count();
    $managerPermissions = Role::findByName('manager', 'api')->permissions()->count();

    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    expect(Permission::count())->toBe($permissions)
        ->and(Role::count())->toBe($roles)
        ->and(Role::findByName('manager', 'api')->permissions()->count())->toBe($managerPermissions);
});

test('el rol admin recibe todos los permisos y el rol user ninguno', function () {
    expect(Role::findByName('admin', 'api')->permissions()->count())->toBe(Permission::count())
        ->and(Role::findByName('user', 'api')->permissions()->count())->toBe(0);
});

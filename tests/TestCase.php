<?php

namespace Tests;

use App\Contracts\HostResolverInterface;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\FakeHostResolver;
use Tests\Support\OpenApiValidator;

abstract class TestCase extends BaseTestCase
{
    /**
     * Creates the application.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        // Asegurar que la aplicación está completamente inicializada
        $app->make(Kernel::class)->bootstrap();

        // Forzar entorno de testing para evitar envío a Sentry
        $app['config']->set('app.env', 'testing');

        // Usar la BD de testing aislada: <BD de desarrollo>_test (ej: apygg_test).
        // La crea ./create_testdb.sh (make testdb / make testdb-migrate).
        // Así los tests nunca tocan la base de desarrollo.
        if (env('DB_TEST_ISOLATION', true)) {
            $connection = config('database.default');
            $testDatabase = config("database.connections.{$connection}.database").'_test';

            // En modo paralelo, cada proceso necesita su propia base: si todos
            // comparten una, se pisan los datos entre sí y los tests fallan de
            // forma intermitente. Créalas con:
            //   ./create_testdb.sh --migrate --parallel=4
            if ($token = ParallelTesting::token()) {
                $testDatabase .= '_'.$token;
            }

            $app['config']->set("database.connections.{$connection}.database", $testDatabase);
        }

        return $app;
    }

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // La caché no la revierte RefreshDatabase: sin limpiarla, un test puede
        // leer lo que cacheó otro (y con IDs auto-incrementales reiniciados,
        // devolver el registro equivocado).
        Cache::flush();

        // DNS falso: los tests no dependen de la red. Los dominios resuelven a una IP pública.
        $this->fakeDns(fn (string $host) => ['93.184.216.34']);

        // Seed roles y permisos base antes de cada test
        $this->seedRolesAndPermissions();
    }

    /**
     * Sustituye el DNS real por uno falso (binding del contenedor, sin estado estático).
     *
     * @param  \Closure(string): array<int, string>  $resolver  host => lista de IPs
     */
    protected function fakeDns(\Closure $resolver): void
    {
        $this->app->instance(HostResolverInterface::class, new FakeHostResolver($resolver));
    }

    /**
     * Seed roles y permisos base para los tests.
     */
    protected function seedRolesAndPermissions(): void
    {
        // La BD de pruebas suele venir sembrada (migrate --seed); comprobarlo cuesta 3 consultas,
        // volver a sembrar ~100 por test. Si falta algo (catálogo o asignaciones nuevas), se siembra.
        if ($this->rolesAndPermissionsAreSeeded()) {
            return;
        }

        $this->seed(RoleSeeder::class); // llama a PermissionSeeder
    }

    /**
     * ¿Están en la BD todos los permisos del catálogo y todas las asignaciones de los roles base?
     */
    private function rolesAndPermissionsAreSeeded(): bool
    {
        $catalog = array_column(PermissionSeeder::catalog(), 'name');
        $existing = Permission::query()->where('guard_name', 'api')->pluck('name')->all();

        if (array_diff($catalog, $existing) !== []) {
            return false;
        }

        $assigned = [];
        foreach (DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.guard_name', 'api')
            ->get(['roles.name as role', 'permissions.name as permission']) as $row) {
            $assigned[$row->role][] = $row->permission;
        }

        foreach (RoleSeeder::definitions() as $role => $definition) {
            if (! array_key_exists($role, $assigned) && $definition['permissions'] !== []) {
                return false;
            }

            if (array_diff($definition['permissions'], $assigned[$role] ?? []) !== []) {
                return false;
            }
        }

        // Roles sin permisos (p. ej. `user`): basta con que existan.
        $roles = DB::table('roles')->where('guard_name', 'api')->pluck('name')->all();

        return array_diff(array_keys(RoleSeeder::definitions()), $roles) === [];
    }

    /**
     * Create a user for testing.
     */
    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /**
     * Create an admin user for testing.
     */
    protected function createAdmin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    /**
     * Act as a user for testing.
     */
    protected function actingAsUser(?User $user = null): User
    {
        if (! $user) {
            $user = $this->createUser();
        }

        $this->actingAs($user, 'api');

        return $user;
    }

    /**
     * Login as a user and return the JWT token.
     */
    protected function loginAs(?User $user = null): string
    {
        if (! $user) {
            $user = $this->createUser();
        }

        return JWTAuth::fromUser($user);
    }

    /**
     * Assert that the response is a successful API response.
     *
     * @param  TestResponse  $response
     */
    protected function assertApiSuccess($response, int $status = 200): void
    {
        $response->assertStatus($status)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    /**
     * Assert that the response is an error API response.
     *
     * @param  TestResponse  $response
     */
    protected function assertApiError($response, int $status = 400): void
    {
        $response->assertStatus($status)
            ->assertJsonStructure([
                'type',
                'title',
                'status',
            ]);
    }

    /**
     * Assert that the response indicates permission denied.
     *
     * @param  TestResponse  $response
     */
    protected function assertPermissionDenied($response): void
    {
        $response->assertStatus(403)
            ->assertJson([
                'status' => 403,
            ]);
    }

    /**
     * Assert that the response indicates unauthorized.
     *
     * @param  TestResponse  $response
     */
    protected function assertUnauthorized($response): void
    {
        $response->assertStatus(401)
            ->assertJson([
                'status' => 401,
            ]);
    }

    /**
     * Afirma que la respuesta cumple el contrato `docs/openapi.json` (status documentado, envelope,
     * tipos y campos obligatorios). Por defecto toma método y plantilla de ruta de la última petición;
     * se pueden forzar para respuestas sin ruta (404 de ruta inexistente).
     *
     * @param  TestResponse  $response
     */
    protected function assertMatchesOpenApi($response, ?string $method = null, ?string $route = null): void
    {
        $problems = OpenApiValidator::problems($response, $method, $route);

        $this->assertSame([], $problems, "La respuesta no cumple docs/openapi.json:\n- ".implode("\n- ", $problems));
    }
}

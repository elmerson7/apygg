<?php

namespace Tests;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        // Asegurar que la aplicación está completamente inicializada
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        // Forzar entorno de testing para evitar envío a Sentry
        $app['config']->set('app.env', 'testing');

        return $app;
    }

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles y permisos base antes de cada test
        $this->seedRolesAndPermissions();
    }

    /**
     * Seed roles y permisos base para los tests.
     */
    protected function seedRolesAndPermissions(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
        ]);
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
     * @param  \Illuminate\Testing\TestResponse  $response
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
     * @param  \Illuminate\Testing\TestResponse  $response
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
     * @param  \Illuminate\Testing\TestResponse  $response
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
     * @param  \Illuminate\Testing\TestResponse  $response
     */
    protected function assertUnauthorized($response): void
    {
        $response->assertStatus(401)
            ->assertJson([
                'status' => 401,
            ]);
    }
}

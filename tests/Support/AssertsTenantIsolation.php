<?php

namespace Tests\Support;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoles;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * PLANTILLA del aislamiento por recurso (docs/multi-tenancy.md §«Cómo probar el aislamiento de un recurso nuevo»).
 *
 * Uso en un test de `tests/Feature/Tenancy/Aislamiento/`:
 *
 *   uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);
 *   beforeEach(fn () => $this->bootIsolation());
 *
 * `bootIsolation()` deja multiempresa activa, dos tenants (`$this->a`, `$this->b`) y el `TenantContext` en `$this->ctx`.
 * Los `assert*` cubren los 4 casos de la plantilla: listado (a), id ajeno (b), `tenant_id` del cuerpo (c) y
 * consulta/modelo (d). Cada test de recurso las llama con sus rutas y fábricas.
 *
 * Requiere `InteractsWithTenancy` y `ActsWithHeaders` en el mismo test.
 */
trait AssertsTenantIsolation
{
    public Tenant $a;

    public Tenant $b;

    public TenantContext $ctx;

    public function bootIsolation(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        $this->enableTenancy();
        $this->a = $this->makeTenant('acme');
        $this->b = $this->makeTenant('beta');
        $this->ctx = app(TenantContext::class);
    }

    /**
     * Usuario miembro ACTIVO del tenant con rol de Spatie `user` y, si se indica, rol DEL TENANT
     * (`tenant_user.role` + rol `tenant-*`) y permisos sueltos.
     *
     * @param  list<string>  $permissions
     */
    public function isoUser(Tenant $tenant, ?string $tenantRole = null, array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::where('name', 'user')->first());
        $tenant->users()->attach($user->getKey(), ['status' => 'active', 'role' => $tenantRole ?? 'member', 'joined_at' => now()]);

        if ($tenantRole !== null) {
            TenantRoles::sync($user, $tenant, $tenantRole);
        }

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /**
     * Cabeceras de `$user` actuando en `$tenant` (se calculan ANTES de `asHeaders()`).
     *
     * @return array<string, string>
     */
    public function isoHeaders(User $user, Tenant $tenant): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'X-Tenant' => (string) $tenant->id];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    public function isoCall(array $headers, string $method, string $uri, array $body = []): TestResponse
    {
        return $this->asHeaders($headers)->json($method, $uri, $body);
    }

    /**
     * (a) El listado desde A devuelve exactamente lo de A: `$field` de cada fila de `data` == `$expected`.
     *
     * @param  array<string, string>  $headers
     * @param  list<mixed>  $expected
     * @param  list<mixed>  $forbidden  valores de B que no pueden aparecer
     */
    public function assertListIsolated(array $headers, string $uri, string $field, array $expected, array $forbidden): void
    {
        $res = $this->isoCall($headers, 'GET', $uri)->assertOk();
        $values = collect($res->json('data'))->pluck($field)->all();

        expect($values)->toEqualCanonicalizing($expected);

        foreach ($forbidden as $value) {
            expect($values)->not->toContain($value);
        }
    }

    /**
     * (b) Cada petición `[método, uri, cuerpo?]` sobre un recurso de B desde A responde 404 (nunca 403:
     * no se confirma que exista).
     *
     * @param  array<string, string>  $headers
     * @param  list<array{0: string, 1: string, 2?: array<string, mixed>}>  $requests
     */
    public function assertForeignIdNotFound(array $headers, array $requests): void
    {
        foreach ($requests as $request) {
            $res = $this->isoCall($headers, $request[0], $request[1], $request[2] ?? []);
            expect($res->getStatusCode())->toBe(404, "{$request[0]} {$request[1]} debía dar 404 y dio {$res->getStatusCode()}");
        }
    }

    /**
     * (c) Crear desde A con `tenant_id` de B en el cuerpo: el registro queda en A (o se rechaza, nunca en B).
     * `$findCreated` recibe la respuesta y devuelve el `tenant_id` guardado (consultando con `withoutTenant`).
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @param  Closure(TestResponse): ?int  $storedTenantId
     */
    public function assertBodyTenantIgnored(array $headers, string $uri, array $body, Closure $storedTenantId): void
    {
        $res = $this->isoCall($headers, 'POST', $uri, $body + ['tenant_id' => $this->b->id]);

        expect($res->getStatusCode())->toBeLessThan(500);

        if ($res->isSuccessful()) {
            expect($storedTenantId($res))->toBe($this->a->id);
        }
    }

    /**
     * (d) Consulta de modelo: dentro de `run(A)` solo salen las filas de A, dentro de `run(B)` las de B y sin
     * contexto (multiempresa activa) falla cerrado con `MissingTenantContext`.
     *
     * @param  class-string  $model
     * @param  Closure(Tenant): mixed  $make  crea UNA fila del recurso para el tenant activo
     */
    public function assertModelIsolated(string $model, Closure $make): void
    {
        $this->ctx->run($this->a, fn () => $make($this->a));
        $this->ctx->run($this->b, fn () => $make($this->b));

        $this->ctx->run($this->a, function () use ($model) {
            expect($model::query()->count())->toBe(1)
                ->and($model::query()->pluck('tenant_id')->unique()->all())->toBe([$this->a->id]);
        });
        $this->ctx->run($this->b, fn () => expect($model::query()->pluck('tenant_id')->all())->toBe([$this->b->id]));

        $this->ctx->clear();
        expect(fn () => $model::query()->count())->toThrow(MissingTenantContext::class);
    }
}

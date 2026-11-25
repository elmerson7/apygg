<?php

use App\Contracts\SubscriptionAccessInterface;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\InteractsWithTenancy;

/*
 * Middleware `tenant` (ResolveTenant): estrategias de resolución (dominio, subdominio, parámetro de ruta con
 * binding, cabecera con espacios), lo que deja en la petición (atributo, idioma, aviso de suscripción) y que no
 * hereda el tenant de antes. Complementa MiddlewareTenantTest con los casos que las pruebas de mutación mostraron
 * sin cubrir.
 */

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    $this->enableTenancy();
    $this->a = $this->makeTenant('acme');
    $this->a->forceFill(['domain' => 'acme-empresa.test', 'locale' => 'en'])->save();
    $this->b = $this->makeTenant('beta');

    $this->user = User::factory()->create(['locale' => null]);
    $this->addMember($this->a, $this->user);
    $this->token = JWTAuth::fromUser($this->user);

    $respuesta = fn () => response()->json([
        'tenant' => app(TenantContext::class)->id(),
        'atributo' => request()->attributes->get('tenant')?->id,
        'idioma' => app()->getLocale(),
    ]);

    Route::middleware(['api', 'auth:api', 'tenant'])->get('/_resolucion/actual', $respuesta);
    Route::middleware(['api', 'auth:api', 'tenant:optional'])->get('/_resolucion/opcional', $respuesta);
    Route::middleware(['api', 'auth:api', 'tenant'])->get('/_resolucion/ruta/{tenant}', fn (Tenant $tenant) => $respuesta());
    Route::middleware(['api', 'tenant'])->get('/_resolucion/sin-auth', $respuesta);
});

function pedirComo($test, string $url, array $cabeceras = [])
{
    return $test->withToken($test->token)->withHeaders($cabeceras)->getJson($url);
}

test('por dominio resuelve el tenant cuyo dominio coincide con el host', function () {
    config(['multi-tenancy.resolution.strategies' => ['domain']]);

    pedirComo($this, 'http://acme-empresa.test/_resolucion/actual')->assertOk()->assertJson(['tenant' => $this->a->id]);
    pedirComo($this, 'http://otro-host.test/_resolucion/actual')->assertForbidden();
});

test('por subdominio resuelve el slug de la primera etiqueta bajo el dominio base', function () {
    config(['multi-tenancy.resolution.strategies' => ['subdomain'], 'multi-tenancy.resolution.base_domain' => 'apygg.test']);

    pedirComo($this, 'http://acme.apygg.test/_resolucion/actual')->assertOk()->assertJson(['tenant' => $this->a->id]);
});

test('por subdominio no resuelve nada con niveles anidados, otro dominio, el dominio base solo o sin dominio base', function (string $host, ?string $base) {
    config(['multi-tenancy.resolution.strategies' => ['subdomain'], 'multi-tenancy.resolution.base_domain' => $base]);

    pedirComo($this, "http://{$host}/_resolucion/actual")->assertStatus(400);
})->with([
    'anidado' => ['x.acme.apygg.test', 'apygg.test'],
    'otro dominio' => ['acme.otro.test', 'apygg.test'],
    'sin punto antes del base' => ['acmeapygg.test', 'apygg.test'],
    'solo el base' => ['apygg.test', 'apygg.test'],
    'base vacío' => ['acme.apygg.test', ''],
    'base nulo' => ['acme.apygg.test', null],
]);

test('por parámetro de ruta con binding usa el modelo Tenant resuelto', function () {
    config(['multi-tenancy.resolution.strategies' => ['route']]);

    pedirComo($this, "/_resolucion/ruta/{$this->a->id}")->assertOk()->assertJson(['tenant' => $this->a->id]);
    pedirComo($this, "/_resolucion/ruta/{$this->b->id}")->assertForbidden();
});

test('la cabecera se recorta y una cabecera de solo espacios cuenta como ausente', function () {
    pedirComo($this, '/_resolucion/actual', ['X-Tenant' => '  acme  '])->assertOk()->assertJson(['tenant' => $this->a->id]);
    pedirComo($this, '/_resolucion/actual', ['X-Tenant' => '   '])->assertStatus(400)->assertJson(['type' => 'tenant_required']);
});

test('las estrategias pueden venir como texto en la configuración', function () {
    config(['multi-tenancy.resolution.strategies' => 'header']);

    pedirComo($this, '/_resolucion/actual', ['X-Tenant' => 'acme'])->assertOk()->assertJson(['tenant' => $this->a->id]);
});

test('deja el tenant en los atributos de la petición y aplica su idioma', function () {
    pedirComo($this, '/_resolucion/actual', ['X-Tenant' => 'acme'])
        ->assertOk()
        ->assertJson(['atributo' => $this->a->id, 'idioma' => 'en']);
});

test('no hereda un tenant fijado antes de la petición', function () {
    app(TenantContext::class)->set($this->b);

    pedirComo($this, '/_resolucion/opcional')->assertOk()->assertJson(['tenant' => null]);
});

test('sin usuario autenticado y con tenant indicado responde 403 sin revelar nada', function () {
    $this->withHeaders(['X-Tenant' => 'acme'])->getJson('/_resolucion/sin-auth')
        ->assertForbidden()
        ->assertJson(['type' => 'tenant_forbidden']);
});

test('añade el aviso de la suscripción solo cuando la política devuelve uno', function () {
    $politica = new class implements SubscriptionAccessInterface
    {
        public ?string $aviso = 'past_due';

        public function stateFor(int $tenantId): array
        {
            return [];
        }

        public function enforce(Request $request, Tenant $tenant): ?string
        {
            return $this->aviso;
        }
    };
    app()->instance(SubscriptionAccessInterface::class, $politica);

    pedirComo($this, '/_resolucion/actual', ['X-Tenant' => 'acme'])->assertOk()->assertHeader('X-Subscription-Warning', 'past_due');

    $politica->aviso = null;
    pedirComo($this, '/_resolucion/actual', ['X-Tenant' => 'acme'])->assertOk()->assertHeaderMissing('X-Subscription-Warning');
});

<?php

use App\Models\Role;
use App\Models\User;
use App\Services\ApiKeyService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;

uses(RefreshDatabase::class, ActsWithHeaders::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->llamadas = 0;

    // Rutas de prueba: cuentan cuántas veces se ejecuta el controller.
    $contador = &$this->llamadas;
    Route::middleware(['auth:api', 'idempotent'])->post('/_idem/opcional', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador, 'secret' => 'whsec_'.bin2hex(random_bytes(4))], 201)->header('X-Custom', 'abc')->header('Set-Cookie', 'a=b');
    });
    Route::middleware(['auth:api', 'idempotent:required'])->post('/_idem/obligatoria', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador], 201);
    });
    Route::middleware(['auth:api', 'idempotent'])->post('/_idem/falla', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador], $contador === 1 ? 503 : 201);
    });
    Route::middleware(['auth:api', 'idempotent'])->post('/_idem/explota', function () use (&$contador) {
        $contador++;
        if ($contador === 1) {
            throw new RuntimeException('boom');
        }

        return response()->json(['n' => $contador], 201);
    });
    Route::middleware(['auth:api', 'idempotent'])->post('/_idem/invalida', function () use (&$contador) {
        $contador++;

        return response()->json(['message' => 'no'], 422);
    });
    Route::middleware(['auth:api', 'idempotent'])->post('/_idem/grande', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador, 'blob' => str_repeat('x', 200)], 201);
    });
    Route::middleware(['auth:api', 'idempotent'])->get('/_idem/lectura', fn () => response()->json(['ok' => true]));
});

function idemUsuario(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'user')->first());

    return $user;
}

function idemPost($t, User $user, string $uri, array $body, ?string $key = 'clave-1', array $extra = [])
{
    $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)] + $extra;
    if ($key !== null) {
        $headers['Idempotency-Key'] = $key;
    }

    return $t->asHeaders($headers)->postJson($uri, $body);
}

test('repetir la misma clave con el mismo cuerpo devuelve la respuesta guardada sin volver a ejecutar', function () {
    $user = idemUsuario();

    $primera = idemPost($this, $user, '/_idem/opcional', ['a' => 1, 'b' => ['x' => 1, 'y' => 2]])->assertCreated();
    $segunda = idemPost($this, $user, '/_idem/opcional', ['b' => ['y' => 2, 'x' => 1], 'a' => 1])->assertCreated();

    expect($this->llamadas)->toBe(1)
        ->and($segunda->json())->toBe($primera->json())
        ->and($segunda->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($primera->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and($segunda->headers->get('X-Custom'))->toBe('abc');
});

test('la respuesta guardada va cifrada y sin cabeceras sensibles', function () {
    $user = idemUsuario();
    $res = idemPost($this, $user, '/_idem/opcional', ['a' => 1]);

    $fila = DB::table('idempotency_keys')->first();

    expect($fila->state)->toBe('completed')
        ->and($fila->response_status)->toBe(201)
        ->and($fila->response_body)->not->toContain($res->json('secret'))
        ->and(strtolower((string) $fila->response_headers))->not->toContain('set-cookie')
        ->and(strtolower((string) $fila->response_headers))->toContain('x-custom');
});

test('la misma clave con otro cuerpo da 422 idempotency_key_mismatch y no ejecuta', function () {
    $user = idemUsuario();
    idemPost($this, $user, '/_idem/opcional', ['a' => 1])->assertCreated();

    idemPost($this, $user, '/_idem/opcional', ['a' => 2])
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_mismatch');

    expect($this->llamadas)->toBe(1);
});

test('la misma clave en otra ruta da 422 idempotency_key_mismatch', function () {
    $user = idemUsuario();
    idemPost($this, $user, '/_idem/opcional', ['a' => 1])->assertCreated();

    idemPost($this, $user, '/_idem/obligatoria', ['a' => 1])
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_mismatch');
});

test('una clave en curso responde 409 idempotency_key_in_progress', function () {
    $user = idemUsuario();
    DB::table('idempotency_keys')->insert([
        'tenant_id' => null, 'actor' => 'user:'.$user->id, 'key' => 'en-curso', 'token' => (string) Str::uuid(),
        'method' => 'POST', 'path' => '/_idem/opcional',
        'request_hash' => hash('sha256', json_encode(['body' => ['a' => 1], 'files' => [], 'query' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'state' => 'processing', 'expires_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    idemPost($this, $user, '/_idem/opcional', ['a' => 1], 'en-curso')
        ->assertStatus(409)->assertJsonPath('code', 'idempotency_key_in_progress');

    expect($this->llamadas)->toBe(0);
});

test('una reserva caducada de un proceso muerto se retoma y ejecuta', function () {
    $user = idemUsuario();
    DB::table('idempotency_keys')->insert([
        'tenant_id' => null, 'actor' => 'user:'.$user->id, 'key' => 'muerta', 'token' => (string) Str::uuid(),
        'method' => 'POST', 'path' => '/_idem/opcional', 'request_hash' => str_repeat('a', 64),
        'state' => 'processing', 'expires_at' => now()->subSecond(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    idemPost($this, $user, '/_idem/opcional', ['a' => 1], 'muerta')->assertCreated();

    expect($this->llamadas)->toBe(1)
        ->and(DB::table('idempotency_keys')->where('key', 'muerta')->value('state'))->toBe('completed');
});

test('una respuesta 5xx no se guarda y el reintento con la misma clave se ejecuta', function () {
    $user = idemUsuario();

    idemPost($this, $user, '/_idem/falla', ['a' => 1])->assertStatus(503);
    expect(DB::table('idempotency_keys')->count())->toBe(0);

    idemPost($this, $user, '/_idem/falla', ['a' => 1])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect($this->llamadas)->toBe(2);
});

test('una excepcion en el controller libera la clave', function () {
    $user = idemUsuario();

    idemPost($this, $user, '/_idem/explota', ['a' => 1])->assertStatus(500);
    expect(DB::table('idempotency_keys')->count())->toBe(0);

    idemPost($this, $user, '/_idem/explota', ['a' => 1])->assertCreated();
});

test('una respuesta 4xx se guarda y se repite; con store_client_errors apagado se libera', function () {
    $user = idemUsuario();

    idemPost($this, $user, '/_idem/invalida', ['a' => 1])->assertStatus(422);
    idemPost($this, $user, '/_idem/invalida', ['a' => 1])->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');
    expect($this->llamadas)->toBe(1);

    config(['idempotency.store_client_errors' => false]);
    idemPost($this, $user, '/_idem/invalida', ['a' => 1], 'otra')->assertStatus(422);
    idemPost($this, $user, '/_idem/invalida', ['a' => 1], 'otra')->assertStatus(422)->assertHeaderMissing('Idempotent-Replayed');
    expect($this->llamadas)->toBe(3);
});

test('una respuesta mayor que el limite se ejecuta pero no se guarda', function () {
    config(['idempotency.max_response_bytes' => 50]);
    $user = idemUsuario();

    idemPost($this, $user, '/_idem/grande', ['a' => 1])->assertCreated();
    idemPost($this, $user, '/_idem/grande', ['a' => 1])->assertCreated();

    expect($this->llamadas)->toBe(2)->and(DB::table('idempotency_keys')->count())->toBe(0);
});

test('sin cabecera la ruta opcional funciona y la obligatoria da 400', function () {
    $user = idemUsuario();

    idemPost($this, $user, '/_idem/opcional', ['a' => 1], null)->assertCreated();
    idemPost($this, $user, '/_idem/obligatoria', ['a' => 1], null)
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    expect(DB::table('idempotency_keys')->count())->toBe(0);
});

test('una clave con caracteres no validos o demasiado larga da 400', function () {
    $user = idemUsuario();

    idemPost($this, $user, '/_idem/opcional', ['a' => 1], 'con espacio')->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
    idemPost($this, $user, '/_idem/opcional', ['a' => 1], str_repeat('k', 256))->assertStatus(400);
});

test('las peticiones de lectura ignoran la cabecera', function () {
    $user = idemUsuario();

    $this->asHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'Idempotency-Key' => 'k'])
        ->getJson('/_idem/lectura')->assertOk()->assertHeaderMissing('Idempotent-Replayed');

    expect(DB::table('idempotency_keys')->count())->toBe(0);
});

test('un usuario no reutiliza ni lee la clave de otro', function () {
    $ana = idemUsuario();
    $beto = idemUsuario();

    $deAna = idemPost($this, $ana, '/_idem/opcional', ['a' => 1], 'compartida')->assertCreated();
    $deBeto = idemPost($this, $beto, '/_idem/opcional', ['a' => 1], 'compartida')->assertCreated();

    expect($this->llamadas)->toBe(2)
        ->and($deBeto->json('n'))->toBe(2)
        ->and($deBeto->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and($deAna->json('secret'))->not->toBe($deBeto->json('secret'))
        ->and(DB::table('idempotency_keys')->count())->toBe(2);
});

test('con la clave caducada se vuelve a ejecutar', function () {
    $user = idemUsuario();
    idemPost($this, $user, '/_idem/opcional', ['a' => 1])->assertCreated();

    $this->travel(25)->hours();

    idemPost($this, $user, '/_idem/opcional', ['a' => 1])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect($this->llamadas)->toBe(2)->and(DB::table('idempotency_keys')->count())->toBe(1);
});

test('con la clave vigente un dia despues todavia se repite', function () {
    $user = idemUsuario();
    idemPost($this, $user, '/_idem/opcional', ['a' => 1])->assertCreated();

    $this->travel(23)->hours();

    idemPost($this, $user, '/_idem/opcional', ['a' => 1])->assertHeader('Idempotent-Replayed', 'true');
});

test('la limpieza borra solo lo caducado', function () {
    $user = idemUsuario();
    idemPost($this, $user, '/_idem/opcional', ['a' => 1], 'vieja')->assertCreated();
    $this->travel(25)->hours();
    idemPost($this, $user, '/_idem/opcional', ['a' => 1], 'nueva')->assertCreated();

    $this->artisan('idempotency:clean --dry-run')->assertSuccessful();
    expect(DB::table('idempotency_keys')->count())->toBe(2);

    $this->artisan('idempotency:clean')->assertSuccessful();
    expect(DB::table('idempotency_keys')->pluck('key')->all())->toBe(['nueva']);
});

test('con IDEMPOTENCY_ENABLED apagado el middleware no hace nada', function () {
    config(['idempotency.enabled' => false]);
    $user = idemUsuario();

    idemPost($this, $user, '/_idem/opcional', ['a' => 1])->assertCreated();
    idemPost($this, $user, '/_idem/opcional', ['a' => 1])->assertCreated();

    expect($this->llamadas)->toBe(2)->and(DB::table('idempotency_keys')->count())->toBe(0);
});

test('POST /webhooks con la misma clave crea un solo webhook y repite el secreto', function () {
    $user = idemUsuario();
    $body = ['name' => 'Mi hook', 'url' => 'https://example.com/hook'];

    $a = idemPost($this, $user, '/webhooks', $body, 'wh-1')->assertCreated();
    $b = idemPost($this, $user, '/webhooks', $body, 'wh-1')->assertCreated();

    expect($b->json('data.secret'))->toBe($a->json('data.secret'))
        ->and($b->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and(DB::table('webhooks')->count())->toBe(1);

    // Sin cabecera sigue funcionando como siempre (cliente actual).
    idemPost($this, $user, '/webhooks', $body, null)->assertCreated();
    expect(DB::table('webhooks')->count())->toBe(2);
});

test('una API key tiene su propio espacio de claves, distinto del de su usuario', function () {
    $user = idemUsuario();
    $creada = app(ApiKeyService::class)->create($user, 'k', ['*']);
    $clave = $creada['key'];

    Route::middleware(['api', 'auth:api-key', 'idempotent'])->post('/_idem/apikey2', fn () => response()->json(['ok' => true], 201));

    $this->asHeaders(['X-API-Key' => $clave, 'Idempotency-Key' => 'k1'])->postJson('/_idem/apikey2', ['a' => 1])->assertCreated();
    $this->asHeaders(['X-API-Key' => $clave, 'Idempotency-Key' => 'k1'])->postJson('/_idem/apikey2', ['a' => 1])->assertHeader('Idempotent-Replayed', 'true');

    // El mismo texto de clave con JWT del mismo usuario es otro actor: no ve la respuesta de la API key.
    idemPost($this, $user, '/_idem/opcional', ['a' => 1], 'k1')->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect(DB::table('idempotency_keys')->pluck('actor')->sort()->values()->all())->toBe(collect(['api_key:'.$creada['api_key']->id, 'user:'.$user->id])->sort()->values()->all());
});

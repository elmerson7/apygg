<?php

use App\Http\Middleware\EnsureIdempotency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Casos límite del middleware `idempotent` (EnsureIdempotency) que las pruebas de mutación mostraron sin cubrir:
 * qué estados se guardan, límites exactos de clave y respuesta, valores por defecto de la configuración, cabeceras
 * que no se guardan, actor anónimo por IP, cuerpos de formulario y archivos en la huella, y la liberación de la
 * clave cuando el siguiente eslabón lanza.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ejecuciones = 0;
    $contador = &$this->ejecuciones;

    Route::middleware(['auth:api', 'idempotent'])->post('/_idem-limites/estado/{codigo}', function (int $codigo) use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador], $codigo);
    });
    Route::middleware(['auth:api', 'idempotent'])->post('/_idem-limites/cuerpo', function () use (&$contador) {
        $contador++;

        return response('x'.str_repeat('y', 9), 201);
    });
    Route::middleware(['auth:api', 'idempotent'])->post('/_idem-limites/cabeceras', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador], 201)->header('X-Privada', 'p')->header('Idempotent-Extra', 'e')->header('X-Publica', 'ok');
    });
    Route::middleware(['auth:api', 'idempotent'])->patch('/_idem-limites/parche', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador]);
    });
    Route::middleware(['auth:api', 'idempotent'])->post('/_idem-limites/archivo', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador], 201);
    });
    Route::middleware(['idempotent'])->post('/_idem-limites/anonima', function () use (&$contador) {
        $contador++;

        return response()->json(['n' => $contador], 201);
    });

    $this->usuario = User::factory()->create();
    $this->token = JWTAuth::fromUser($this->usuario);
});

function idemLimite($test, string $uri, array $cuerpo = ['a' => 1], ?string $clave = 'clave-limite', string $metodo = 'postJson')
{
    $cabeceras = ['Authorization' => 'Bearer '.$test->token];
    if ($clave !== null) {
        $cabeceras['Idempotency-Key'] = $clave;
    }

    return $test->withHeaders($cabeceras)->{$metodo}($uri, $cuerpo);
}

test('las respuestas 408, 425, 429 y 5xx no se guardan y el reintento vuelve a ejecutar', function (int $codigo) {
    idemLimite($this, "/_idem-limites/estado/{$codigo}")->assertStatus($codigo);
    idemLimite($this, "/_idem-limites/estado/{$codigo}")->assertStatus($codigo)->assertHeaderMissing('Idempotent-Replayed');

    expect($this->ejecuciones)->toBe(2)
        ->and(DB::table('idempotency_keys')->count())->toBe(0);
})->with([408, 425, 429, 500, 503]);

test('las respuestas 200 y los errores de cliente justo bajo 500 se guardan y se repiten', function (int $codigo) {
    idemLimite($this, "/_idem-limites/estado/{$codigo}")->assertStatus($codigo);
    idemLimite($this, "/_idem-limites/estado/{$codigo}")->assertStatus($codigo)->assertHeader('Idempotent-Replayed', 'true');

    expect($this->ejecuciones)->toBe(1);
})->with([200, 400, 499]);

test('con store_client_errors apagado un 400 no se guarda', function () {
    config(['idempotency.store_client_errors' => false]);

    idemLimite($this, '/_idem-limites/estado/400')->assertStatus(400);
    idemLimite($this, '/_idem-limites/estado/400')->assertStatus(400)->assertHeaderMissing('Idempotent-Replayed');

    expect($this->ejecuciones)->toBe(2);
});

test('sin las claves de configuración se aplican los valores por defecto: activo, POST y PATCH y errores de cliente guardados', function () {
    config(['idempotency' => array_diff_key(config('idempotency'), array_flip(['enabled', 'methods', 'store_client_errors', 'max_key_length', 'max_response_bytes']))]);

    idemLimite($this, '/_idem-limites/estado/422')->assertStatus(422);
    idemLimite($this, '/_idem-limites/estado/422')->assertHeader('Idempotent-Replayed', 'true');
    idemLimite($this, '/_idem-limites/parche', clave: 'parche', metodo: 'patchJson')->assertOk();
    idemLimite($this, '/_idem-limites/parche', clave: 'parche', metodo: 'patchJson')->assertHeader('Idempotent-Replayed', 'true');
    idemLimite($this, '/_idem-limites/estado/201', clave: str_repeat('k', 255))->assertCreated();
    idemLimite($this, '/_idem-limites/estado/201', clave: str_repeat('k', 256))->assertStatus(400);

    expect($this->ejecuciones)->toBe(3);
});

test('los métodos de la configuración se comparan sin distinguir mayúsculas', function () {
    config(['idempotency.methods' => ['post']]);

    idemLimite($this, '/_idem-limites/estado/201')->assertCreated();
    idemLimite($this, '/_idem-limites/estado/201')->assertHeader('Idempotent-Replayed', 'true');

    expect($this->ejecuciones)->toBe(1);
});

test('una clave de exactamente la longitud máxima se acepta y una más larga no', function () {
    config(['idempotency.max_key_length' => 10]);

    idemLimite($this, '/_idem-limites/estado/201', clave: str_repeat('k', 10))->assertCreated();
    idemLimite($this, '/_idem-limites/estado/201', clave: str_repeat('k', 11))->assertStatus(400);
});

test('una cabecera vacía cuenta como ausente', function () {
    idemLimite($this, '/_idem-limites/estado/201', clave: '')->assertCreated();

    expect(DB::table('idempotency_keys')->count())->toBe(0);
});

test('una respuesta de exactamente el tamaño máximo se guarda y una de un byte más no', function () {
    config(['idempotency.max_response_bytes' => 10]);

    idemLimite($this, '/_idem-limites/cuerpo')->assertCreated();
    idemLimite($this, '/_idem-limites/cuerpo')->assertHeader('Idempotent-Replayed', 'true');

    config(['idempotency.max_response_bytes' => 9]);
    idemLimite($this, '/_idem-limites/cuerpo', clave: 'otra')->assertCreated();
    idemLimite($this, '/_idem-limites/cuerpo', clave: 'otra')->assertHeaderMissing('Idempotent-Replayed');

    expect($this->ejecuciones)->toBe(3);
});

test('no guarda las cabeceras sensibles aunque la configuración las escriba en mayúsculas ni las Idempotent-*', function () {
    config(['idempotency.sensitive_headers' => ['X-Privada']]);

    idemLimite($this, '/_idem-limites/cabeceras')->assertCreated();
    $repetida = idemLimite($this, '/_idem-limites/cabeceras');

    $repetida->assertHeader('Idempotent-Replayed', 'true')
        ->assertHeader('X-Publica', 'ok')
        ->assertHeaderMissing('X-Privada')
        ->assertHeaderMissing('Idempotent-Extra');
});

test('sin usuario la clave pertenece a la IP', function () {
    $this->withHeaders(['Idempotency-Key' => 'anonima'])->postJson('/_idem-limites/anonima', ['a' => 1])->assertCreated();
    $this->withHeaders(['Idempotency-Key' => 'anonima'])->postJson('/_idem-limites/anonima', ['a' => 1])->assertHeader('Idempotent-Replayed', 'true');

    expect(DB::table('idempotency_keys')->value('actor'))->toBe('ip:127.0.0.1')
        ->and($this->ejecuciones)->toBe(1);
});

test('en un formulario la misma clave con otro cuerpo se rechaza', function () {
    idemLimite($this, '/_idem-limites/estado/201', ['a' => 1], metodo: 'post')->assertCreated();
    idemLimite($this, '/_idem-limites/estado/201', ['a' => 2], metodo: 'post')
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_mismatch');
});

test('la huella incluye nombre y contenido de los archivos, también en listas de archivos', function () {
    $subir = fn (array $archivos) => $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Idempotency-Key' => 'archivo'])
        ->post('/_idem-limites/archivo', $archivos);

    $subir(['doc' => UploadedFile::fake()->createWithContent('a.txt', 'uno'), 'anexos' => [UploadedFile::fake()->createWithContent('b.txt', 'dos')]])->assertCreated();
    $subir(['doc' => UploadedFile::fake()->createWithContent('a.txt', 'uno'), 'anexos' => [UploadedFile::fake()->createWithContent('b.txt', 'dos')]])
        ->assertHeader('Idempotent-Replayed', 'true');

    $subir(['doc' => UploadedFile::fake()->createWithContent('a.txt', 'UNO'), 'anexos' => [UploadedFile::fake()->createWithContent('b.txt', 'dos')]])->assertStatus(422);
    $subir(['doc' => UploadedFile::fake()->createWithContent('otro.txt', 'uno'), 'anexos' => [UploadedFile::fake()->createWithContent('b.txt', 'dos')]])->assertStatus(422);
    $subir(['doc' => UploadedFile::fake()->createWithContent('a.txt', 'uno'), 'anexos' => [UploadedFile::fake()->createWithContent('b.txt', 'DOS')]])->assertStatus(422);

    expect($this->ejecuciones)->toBe(1);
});

test('si el siguiente eslabón lanza, la clave se libera y la excepción sigue', function () {
    $peticion = Request::create('/_idem-limites/directa', 'POST', ['a' => 1]);
    $peticion->headers->set('Idempotency-Key', 'directa');

    expect(fn () => app(EnsureIdempotency::class)->handle($peticion, fn () => throw new RuntimeException('fallo')))
        ->toThrow(RuntimeException::class, 'fallo');

    expect(DB::table('idempotency_keys')->count())->toBe(0);
});

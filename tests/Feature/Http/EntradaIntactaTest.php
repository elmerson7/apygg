<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// LOG-16: la API guarda lo que recibe (validado); quien pinta HTML escapa.
// Solo actúan los middleware estándar de Laravel: TrimStrings y
// ConvertEmptyStringsToNull. Estas pruebas ejercen el grupo `api` completo.

beforeEach(function () {
    Route::middleware('api')->any('/_eco-entrada', fn (Request $request) => response()->json([
        'all' => $request->all(),
        'query' => $request->query(),
    ]));
});

function eco(array $datos): array
{
    return test()->postJson('/_eco-entrada', $datos)->assertOk()->json('all');
}

test('entrega intactos los valores que parecen booleanos', function () {
    $recibido = eco(['a' => 'no', 'b' => '1', 'c' => 'on', 'd' => 'off', 'e' => 'yes', 'f' => '0']);

    expect($recibido)->toBe(['a' => 'no', 'b' => '1', 'c' => 'on', 'd' => 'off', 'e' => 'yes', 'f' => '0']);
});

test('no convierte a booleano los campos llamados status, active o enabled', function () {
    $recibido = eco(['status' => 'active', 'active' => 'no', 'enabled' => '1', 'is_active' => 'off']);

    expect($recibido)->toBe(['status' => 'active', 'active' => 'no', 'enabled' => '1', 'is_active' => 'off']);
});

test('no convierte a booleano los filtros de consulta como user_id', function () {
    $consulta = test()->getJson('/_eco-entrada?user_id=1&status=active&page=007')->assertOk()->json('query');

    expect($consulta)->toBe(['user_id' => '1', 'status' => 'active', 'page' => '007']);
});

test('guarda el texto con HTML tal cual', function () {
    $texto = "Hola <b>mundo</b> a <b y c>\nsegunda  línea <script>alert(1)</script>";

    expect(eco(['descripcion' => $texto])['descripcion'])->toBe($texto);
});

test('no copia los valores anidados a la raíz de la petición', function () {
    $recibido = eco(['profile' => ['email' => 'x@y.com', 'name' => 'Ana']]);

    expect($recibido)->toBe(['profile' => ['email' => 'x@y.com', 'name' => 'Ana']])
        ->and($recibido)->not->toHaveKey('email');
});

test('conserva los números con ceros a la izquierda como texto', function () {
    expect(eco(['codigo' => '007', 'id' => '0123', 'price' => '10.50']))
        ->toBe(['codigo' => '007', 'id' => '0123', 'price' => '10.50']);
});

test('recorta los espacios alrededor de los textos', function () {
    expect(eco(['nombre' => '  Ana  ', 'anidado' => ['x' => ' y ']]))
        ->toBe(['nombre' => 'Ana', 'anidado' => ['x' => 'y']]);
});

test('convierte la cadena vacía en null', function () {
    expect(eco(['nombre' => '', 'otro' => '   ']))->toBe(['nombre' => null, 'otro' => null]);
});

test('no recorta ni altera las contraseñas', function () {
    $clave = '  a  <b> & c  ';

    expect(eco(['password' => $clave, 'password_confirmation' => $clave, 'current_password' => $clave]))
        ->toBe(['password' => $clave, 'password_confirmation' => $clave, 'current_password' => $clave]);
});

test('registra un usuario con nombre "no" y una contraseña con símbolos y espacios dobles', function () {
    $clave = 'Aa1  <b>&  zZ9';

    $this->postJson('/auth/register', [
        'name' => 'no',
        'email' => 'sinfiltro@example.com',
        'password' => $clave,
        'password_confirmation' => $clave,
    ])->assertSuccessful();

    $usuario = User::where('email', 'sinfiltro@example.com')->firstOrFail();

    expect($usuario->name)->toBe('no')
        ->and(Hash::check($clave, $usuario->password))->toBeTrue();
});

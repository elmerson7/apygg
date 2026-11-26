<?php

use App\Models\File;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * Reprocesado opcional de imágenes en la subida normal (`files.image_reencode`, docs/files.md): la imagen se decodifica
 * y se vuelve a codificar con GD, así que lo que vaya escondido tras ella (archivo políglota) se pierde.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::where('name', 'user')->first());
});

function imagenConCarga(string $formato): string
{
    $imagen = imagecreatetruecolor(8, 8);
    imagefill($imagen, 0, 0, imagecolorallocate($imagen, 200, 30, 30));
    ob_start();
    $formato === 'png' ? imagepng($imagen) : imagejpeg($imagen);
    imagedestroy($imagen);

    return ob_get_clean().'<?php echo "carga escondida"; ?>';
}

function subirImagen($test, string $nombre, string $contenido)
{
    $ruta = tempnam(sys_get_temp_dir(), 'apygg');
    file_put_contents($ruta, $contenido);

    return $test->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($test->user)])
        ->postJson('/files', ['file' => new UploadedFile($ruta, $nombre, null, null, true)]);
}

function guardado(string $id): string
{
    $file = File::findOrFail($id);

    return (string) Storage::disk($file->disk)->get($file->path);
}

test('apagado (por defecto) la imagen se guarda tal cual, con lo que lleve detrás', function () {
    $id = subirImagen($this, 'foto.png', imagenConCarga('png'))->assertCreated()->json('data.id');

    expect(guardado($id))->toContain('carga escondida');
});

test('encendido un PNG o un JPEG se recodifica y pierde la carga escondida, y el tamaño guardado es el real', function (string $formato) {
    config(['files.image_reencode.enabled' => true]);

    $res = subirImagen($this, "foto.{$formato}", imagenConCarga($formato))->assertCreated();
    $contenido = guardado($res->json('data.id'));

    expect($contenido)->not->toContain('carga escondida')
        ->and(getimagesizefromstring($contenido)[0])->toBe(8)
        ->and($res->json('data.size'))->toBe(strlen($contenido));
})->with(['png', 'jpg']);

test('encendido una imagen con demasiados píxeles se rechaza con 422 y no se guarda', function () {
    config(['files.image_reencode.enabled' => true, 'files.image_reencode.max_megapixels' => 0]);

    subirImagen($this, 'foto.png', imagenConCarga('png'))->assertStatus(422);

    expect(File::query()->count())->toBe(0);
});

test('encendido un archivo que no es imagen no se toca', function () {
    config(['files.image_reencode.enabled' => true]);

    $id = subirImagen($this, 'nota.txt', 'texto <?php ?> normal')->assertCreated()->json('data.id');

    expect(guardado($id))->toBe('texto <?php ?> normal');
});

<?php

use App\Contracts\SettingsRegistryInterface;
use App\Models\File;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('public');
    Storage::fake('local');
    // Los tests de archivos públicos parten de compartir permitido (por defecto está apagado).
    app(SettingsRegistryInterface::class)->setPlatform('security.allow_public_sharing', true);
});

function archivosCabecera(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function archivosUsuario(string $rol = 'user'): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', $rol)->first());

    return $user;
}

/** Sube un archivo REAL (no el falso de Laravel): el tipo se detecta por su contenido. */
function archivoReal(string $nombre, string $contenido): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'apygg');
    file_put_contents($ruta, $contenido);

    return new UploadedFile($ruta, $nombre, null, null, true);
}

function pngMinimo(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

// ── Subida: contenido real, nombres y tamaño ───────────────────────────────

test('un PNG real se sube y se guarda con un nombre generado', function () {
    $user = archivosUsuario();

    $res = $this->withHeaders(archivosCabecera($user))
        ->postJson('/files', ['file' => archivoReal('mi foto.png', pngMinimo()), 'is_public' => true])
        ->assertCreated()
        ->assertJsonPath('data.mime_type', 'image/png')
        ->assertJsonPath('data.extension', 'png');

    $archivo = File::findOrFail($res->json('data.id'));

    expect($archivo->filename)->toMatch('/^[0-9a-z]{26}\.png$/')
        ->and($archivo->filename)->not->toContain('foto')
        ->and($archivo->name)->toBe('mi foto.png');
    Storage::disk('public')->assertExists($archivo->path);
});

test('un SVG con script se rechaza y no se guarda nada', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

    $this->withHeaders(archivosCabecera(archivosUsuario()))
        ->postJson('/files', ['file' => archivoReal('logo.svg', $svg), 'is_public' => true])
        ->assertUnprocessable();

    expect(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(File::count())->toBe(0);
});

test('un HTML disfrazado de PNG se rechaza porque se mira el contenido', function () {
    $this->withHeaders(archivosCabecera(archivosUsuario()))
        ->postJson('/files', ['file' => archivoReal('inocente.png', '<html><script>alert(1)</script></html>')])
        ->assertUnprocessable();

    expect(File::count())->toBe(0);
});

test('un PNG con extension html se rechaza', function () {
    $this->withHeaders(archivosCabecera(archivosUsuario()))
        ->postJson('/files', ['file' => archivoReal('pagina.html', pngMinimo())])
        ->assertUnprocessable();

    expect(File::count())->toBe(0);
});

test('un nombre con path traversal no afecta a la ruta ni al nombre guardado', function () {
    $res = $this->withHeaders(archivosCabecera(archivosUsuario()))
        ->postJson('/files', ['file' => archivoReal('../../../etc/passwd.png', pngMinimo()), 'is_public' => true])
        ->assertCreated();

    $archivo = File::findOrFail($res->json('data.id'));

    expect($archivo->path)->not->toContain('..')
        ->and($archivo->filename)->not->toContain('/')
        ->and($archivo->name)->not->toContain('/')
        ->and($archivo->name)->toBe('passwd.png');
});

test('un archivo mayor que el maximo configurado se rechaza', function () {
    config(['files.max_sizes.document' => 1024]);

    $this->withHeaders(archivosCabecera(archivosUsuario()))
        ->postJson('/files', ['file' => archivoReal('grande.txt', str_repeat('a', 4096))])
        ->assertUnprocessable();

    expect(File::count())->toBe(0);
});

test('subir un archivo sin autenticar responde 401', function () {
    $this->postJson('/files', ['file' => archivoReal('a.png', pngMinimo())])->assertUnauthorized();
});

// ── Privado de verdad ─────────────────────────────────────────────────────

test('un archivo privado va al disco privado, sin url publica ni ruta expuesta', function () {
    $res = $this->withHeaders(archivosCabecera(archivosUsuario()))
        ->postJson('/files', ['file' => archivoReal('secreto.png', pngMinimo()), 'is_public' => false])
        ->assertCreated()
        ->assertJsonPath('data.url', null)
        ->assertJsonMissingPath('data.path')
        ->assertJsonMissingPath('data.disk');

    $archivo = File::findOrFail($res->json('data.id'));

    Storage::disk('local')->assertExists($archivo->path);
    Storage::disk('public')->assertMissing($archivo->path);
    expect($archivo->url)->toBeNull();
});

test('un archivo publico va al disco publico y tiene url', function () {
    $res = $this->withHeaders(archivosCabecera(archivosUsuario()))
        ->postJson('/files', ['file' => archivoReal('logo.png', pngMinimo()), 'is_public' => true])
        ->assertCreated();

    expect($res->json('data.url'))->not->toBeNull();
    Storage::disk('public')->assertExists($res->json('data.path'));
});

function archivoPrivadoDe(User $dueno): File
{
    $ruta = 'uploads/privado.png';
    Storage::disk('local')->put($ruta, pngMinimo());

    return File::factory()->create([
        'user_id' => $dueno->id, 'name' => 'privado.png', 'filename' => 'privado.png', 'path' => $ruta,
        'disk' => 'local', 'url' => null, 'mime_type' => 'image/png', 'type' => 'image', 'is_public' => false, 'expires_at' => null,
    ]);
}

test('el dueno descarga su archivo privado con cabeceras seguras', function () {
    $dueno = archivosUsuario();
    $archivo = archivoPrivadoDe($dueno);

    $this->withHeaders(archivosCabecera($dueno))->get("/files/{$archivo->id}/download")
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('privado.png');
});

test('otro usuario no descarga ni ve el archivo privado', function () {
    $archivo = archivoPrivadoDe(archivosUsuario());
    $otro = archivosUsuario();

    $this->withHeaders(archivosCabecera($otro))->get("/files/{$archivo->id}/download")->assertForbidden();
    $this->withHeaders(archivosCabecera($otro))->getJson("/files/{$archivo->id}")->assertForbidden();
    $this->withHeaders(archivosCabecera($otro))->getJson("/files/{$archivo->id}/temporary-url")->assertForbidden();
});

test('quien tiene files.manage descarga archivos ajenos y sin el permiso no', function () {
    $archivo = archivoPrivadoDe(archivosUsuario());
    $gestor = archivosUsuario();
    $gestor->givePermissionTo('files.manage');

    $this->withHeaders(archivosCabecera($gestor))->get("/files/{$archivo->id}/download")->assertOk();
});

test('descargar un archivo privado sin autenticar responde 401', function () {
    $archivo = archivoPrivadoDe(archivosUsuario());

    $this->getJson("/files/{$archivo->id}/download")->assertUnauthorized();
});

test('la url temporal firmada permite descargar sin token y caduca', function () {
    $dueno = archivosUsuario();
    $archivo = archivoPrivadoDe($dueno);

    $url = $this->withHeaders(archivosCabecera($dueno))->getJson("/files/{$archivo->id}/temporary-url")
        ->assertOk()->json('data.url');

    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    // firma manipulada
    $this->get($url.'x')->assertForbidden();
    // sin firma
    $this->get("/files/{$archivo->id}/signed")->assertForbidden();
    // caducada
    $this->travel(config('files.temporary_url_minutes') + 1)->minutes();
    $this->get($url)->assertForbidden();
});

test('pasar un archivo de privado a publico lo mueve de disco', function () {
    $dueno = archivosUsuario();
    $archivo = archivoPrivadoDe($dueno);

    $this->withHeaders(archivosCabecera($dueno))
        ->putJson("/files/{$archivo->id}", ['is_public' => true])
        ->assertOk()
        ->assertJsonPath('data.is_public', true);

    Storage::disk('public')->assertExists($archivo->path);
    Storage::disk('local')->assertMissing($archivo->path);

    $this->withHeaders(archivosCabecera($dueno))
        ->putJson("/files/{$archivo->id}", ['is_public' => false])
        ->assertOk()
        ->assertJsonPath('data.url', null);

    Storage::disk('local')->assertExists($archivo->path);
    Storage::disk('public')->assertMissing($archivo->path);
});

test('otro usuario no puede editar ni borrar el archivo', function () {
    $archivo = archivoPrivadoDe(archivosUsuario());
    $otro = archivosUsuario();

    $this->withHeaders(archivosCabecera($otro))->putJson("/files/{$archivo->id}", ['description' => 'x'])->assertForbidden();
    $this->withHeaders(archivosCabecera($otro))->deleteJson("/files/{$archivo->id}")->assertForbidden();
    Storage::disk('local')->assertExists($archivo->path);
});

test('el dueno borra su archivo y desaparece del disco', function () {
    $dueno = archivosUsuario();
    $archivo = archivoPrivadoDe($dueno);

    $this->withHeaders(archivosCabecera($dueno))->deleteJson("/files/{$archivo->id}")->assertOk();

    Storage::disk('local')->assertMissing($archivo->path);
    expect(File::find($archivo->id))->toBeNull();
});

test('el listado solo muestra los archivos propios', function () {
    $yo = archivosUsuario();
    archivoPrivadoDe($yo);
    File::factory()->create(['user_id' => archivosUsuario()->id]);

    $this->withHeaders(archivosCabecera($yo))->getJson('/files')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('la ruta raiz sigue respondiendo la ficha de la API', function () {
    $this->getJson('/')->assertOk()->assertJsonPath('name', config('app.title'));
});

test('subir un archivo publico con compartir apagado responde 403 con code estable', function () {
    app(SettingsRegistryInterface::class)->setPlatform('security.allow_public_sharing', false);

    $this->withHeaders(archivosCabecera(archivosUsuario()))
        ->postJson('/files', ['file' => archivoReal('foto.png', pngMinimo()), 'is_public' => true])
        ->assertForbidden()->assertJsonPath('code', 'public_sharing_disabled');

    expect(File::count())->toBe(0);
});

test('subir un archivo privado con compartir apagado sigue permitido', function () {
    app(SettingsRegistryInterface::class)->setPlatform('security.allow_public_sharing', false);

    $this->withHeaders(archivosCabecera(archivosUsuario()))
        ->postJson('/files', ['file' => archivoReal('foto.png', pngMinimo()), 'is_public' => false])
        ->assertCreated();
});

test('hacer publico un archivo con compartir apagado responde 403 y no cambia su visibilidad', function () {
    $user = archivosUsuario();
    $this->withHeaders(archivosCabecera($user))
        ->postJson('/files', ['file' => archivoReal('foto.png', pngMinimo()), 'is_public' => false])->assertCreated();
    $archivo = File::first();
    app(SettingsRegistryInterface::class)->setPlatform('security.allow_public_sharing', false);

    $this->withHeaders(archivosCabecera($user))->putJson("/files/{$archivo->id}", ['is_public' => true])
        ->assertForbidden()->assertJsonPath('code', 'public_sharing_disabled');

    expect($archivo->fresh()->is_public)->toBeFalse();
});

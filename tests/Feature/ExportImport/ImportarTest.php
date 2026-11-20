<?php

use App\Jobs\PreviewImportJob;
use App\Models\DataImport;
use App\Models\InboxNotification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('local');
});

function impAdmin(): User
{
    $u = User::factory()->create();
    $u->assignRole(Role::where('name', 'admin')->first());

    return $u;
}

function impH(User $u): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($u)];
}

/** Cliente actuando como `$u` (limpia el guard JWT de la petición anterior). */
function impAs(object $test, User $u): object
{
    $token = JWTAuth::fromUser($u);
    auth()->forgetGuards();
    app('tymon.jwt')->unsetToken();
    app('tymon.jwt.auth')->unsetToken();

    return $test->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$token]);
}

function impCsv(string $contenido, string $nombre = 'usuarios.csv'): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($ruta, $contenido);

    return new UploadedFile($ruta, $nombre, 'text/csv', null, true);
}

const IMP_CSV = "email,name,username\nana@example.com,Ana,ana.g\nbad-email,Sin Correo,\nluis@example.com,,\nmaria@example.com,Maria,\n";

function impSubir(object $test, User $admin, string $csv = IMP_CSV): int
{
    return impAs($test, $admin)->post('/admin/imports/users', ['file' => impCsv($csv)], ['Accept' => 'application/json'])
        ->assertStatus(202)->json('data.id');
}

test('subir un CSV valida todas las filas y deja una vista previa sin guardar nada', function () {
    $admin = impAdmin();
    $id = impSubir($this, $admin);

    $res = impAs($this, $admin)->getJson("/admin/imports/{$id}")->assertOk();

    expect($res->json('data.status'))->toBe('previewed')
        ->and($res->json('data.total_rows'))->toBe(4)
        ->and($res->json('data.valid_rows'))->toBe(2)
        ->and($res->json('data.invalid_rows'))->toBe(2)
        ->and($res->json('data.preview.sample'))->toHaveCount(2)
        ->and(collect($res->json('data.preview.errors'))->pluck('row')->all())->toBe([3, 4])
        ->and($res->json('data.has_errors_report'))->toBeTrue();

    expect(User::where('email', 'ana@example.com')->exists())->toBeFalse();
});

test('confirmar crea solo las filas válidas y el informe de errores se descarga', function () {
    $admin = impAdmin();
    $id = impSubir($this, $admin);

    impAs($this, $admin)->postJson("/admin/imports/{$id}/confirm")->assertStatus(202);

    $import = DataImport::findOrFail($id);
    expect($import->status)->toBe('completed')->and($import->created_rows)->toBe(2)->and($import->skipped_rows)->toBe(0);
    expect(User::whereIn('email', ['ana@example.com', 'maria@example.com'])->count())->toBe(2)
        ->and(User::where('email', 'luis@example.com')->exists())->toBeFalse();

    $csv = impAs($this, $admin)->get("/admin/imports/{$id}/errors")->assertOk()->streamedContent();
    expect($csv)->toContain('bad-email')->and($csv)->toContain('luis@example.com');
});

test('importar dos veces el mismo archivo no duplica usuarios', function () {
    $admin = impAdmin();

    foreach ([1, 2] as $vez) {
        $id = impSubir($this, $admin);
        impAs($this, $admin)->postJson("/admin/imports/{$id}/confirm")->assertStatus(202);
    }

    expect(User::where('email', 'ana@example.com')->count())->toBe(1);
    $segunda = DataImport::latest('id')->first();
    expect($segunda->created_rows)->toBe(0)->and($segunda->skipped_rows)->toBe(2);
});

test('el email existente se omite sin distinguir mayúsculas', function () {
    $admin = impAdmin();
    User::factory()->create(['email' => 'ana@example.com']);

    $id = impSubir($this, $admin, "email,name\nANA@Example.com,Ana\nnueva@example.com,Nueva\n");
    impAs($this, $admin)->postJson("/admin/imports/{$id}/confirm")->assertStatus(202);

    $import = DataImport::findOrFail($id);
    expect($import->created_rows)->toBe(1)->and($import->skipped_rows)->toBe(1);
});

test('confirmar dos veces la misma importación responde 409 la segunda', function () {
    $admin = impAdmin();
    $id = impSubir($this, $admin);

    impAs($this, $admin)->postJson("/admin/imports/{$id}/confirm")->assertStatus(202);
    impAs($this, $admin)->postJson("/admin/imports/{$id}/confirm")->assertStatus(409)->assertJsonPath('code', 'import_not_confirmable');
});

test('no se puede confirmar si no hay filas válidas', function () {
    $admin = impAdmin();
    $id = impSubir($this, $admin, "email,name\nmalo,\n");

    impAs($this, $admin)->postJson("/admin/imports/{$id}/confirm")->assertStatus(422)->assertJsonPath('code', 'import_nothing_to_import');
});

test('un archivo sin las columnas obligatorias queda fallido con su motivo', function () {
    $admin = impAdmin();
    $id = impSubir($this, $admin, "correo,nombre\na@b.com,A\n");

    $res = impAs($this, $admin)->getJson("/admin/imports/{$id}")->assertOk();
    expect($res->json('data.status'))->toBe('failed')->and($res->json('data.error'))->toContain('email');
    impAs($this, $admin)->postJson("/admin/imports/{$id}/confirm")->assertStatus(409);
});

test('acepta CSV con punto y coma y supera el límite de filas con error', function () {
    $admin = impAdmin();
    $id = impSubir($this, $admin, "email;name\nuno@example.com;Uno\n");
    expect(DataImport::findOrFail($id)->valid_rows)->toBe(1);

    config(['export.import.max_rows' => 2]);
    $id = impSubir($this, $admin, "email,name\na@example.com,A\nb@example.com,B\nc@example.com,C\n");
    expect(DataImport::findOrFail($id)->status)->toBe('failed');
});

test('procesa por lotes pequeños', function () {
    config(['export.import.batch_size' => 2]);
    $admin = impAdmin();
    $filas = collect(range(1, 5))->map(fn ($i) => "u{$i}@example.com,Usuario {$i}")->implode("\n");

    $id = impSubir($this, $admin, "email,name\n{$filas}\n");
    impAs($this, $admin)->postJson("/admin/imports/{$id}/confirm")->assertStatus(202);

    expect(DataImport::findOrFail($id)->created_rows)->toBe(5);
});

test('sin permiso 403, sin token 401 y otro admin no ve la importación (404)', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'user')->first());

    impAs($this, $user)->post('/admin/imports/users', ['file' => impCsv(IMP_CSV)], ['Accept' => 'application/json'])->assertForbidden();
    auth()->forgetGuards();
    app('tymon.jwt')->unsetToken();
    app('tymon.jwt.auth')->unsetToken();
    $this->flushHeaders()->post('/admin/imports/users', ['file' => impCsv(IMP_CSV)], ['Accept' => 'application/json'])->assertUnauthorized();

    $admin = impAdmin();
    $id = impSubir($this, $admin);
    $otro = impAdmin();
    impAs($this, $otro)->getJson("/admin/imports/{$id}")->assertNotFound();
    impAs($this, $otro)->postJson("/admin/imports/{$id}/confirm")->assertNotFound();
    impAs($this, $otro)->get("/admin/imports/{$id}/errors")->assertNotFound();
});

test('la subida exige un archivo con extensión permitida', function () {
    $admin = impAdmin();

    impAs($this, $admin)->postJson('/admin/imports/users', [])->assertStatus(422);
    impAs($this, $admin)->post('/admin/imports/users', ['file' => impCsv('x', 'malo.php')], ['Accept' => 'application/json'])->assertStatus(422);
});

test('con la cola real la subida solo encola y no valida en la petición', function () {
    Queue::fake();
    $admin = impAdmin();
    $id = impSubir($this, $admin);

    expect(DataImport::findOrFail($id)->status)->toBe('validating');
    Queue::assertPushed(PreviewImportJob::class);
});

test('al completar avisa al usuario en su bandeja', function () {
    $admin = impAdmin();
    $id = impSubir($this, $admin);
    impAs($this, $admin)->postJson("/admin/imports/{$id}/confirm")->assertStatus(202);

    $aviso = InboxNotification::query()->where('user_id', $admin->id)->where('type', 'import_finished')->first();
    expect($aviso)->not->toBeNull()->and($aviso->body)->toContain('2 creadas');
});

test('exports:prune pasa a failed las importaciones atascadas y deja las recientes', function () {
    config(['export.stuck_minutes' => 30]);
    $admin = impAdmin();
    $base = ['user_id' => $admin->id, 'importer' => 'users', 'original_name' => 'x.csv', 'disk' => 'local', 'path' => 'x.csv', 'file_hash' => 'h'];
    $atascada = DataImport::create($base + ['status' => 'processing']);
    $reciente = DataImport::create($base + ['status' => 'processing']);
    $previa = DataImport::create($base + ['status' => 'previewed']);
    DataImport::whereIn('id', [$atascada->id, $previa->id])->update(['updated_at' => now()->subMinutes(45)]);

    $this->artisan('exports:prune')->assertExitCode(0);

    expect($atascada->fresh()->status)->toBe('failed')
        ->and($reciente->fresh()->status)->toBe('processing')
        ->and($previa->fresh()->status)->toBe('previewed'); // esperando confirmación: no es un trabajo atascado
});

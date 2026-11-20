<?php

use App\Contracts\ExportServiceInterface;
use App\Models\DataExport;
use App\Models\InboxNotification;
use App\Models\Role;
use App\Models\User;
use App\Support\Spreadsheet\SpreadsheetReader;
use App\Support\Spreadsheet\SpreadsheetWriter;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('local');
});

function expAdmin(): User
{
    $u = User::factory()->create();
    $u->assignRole(Role::where('name', 'admin')->first());

    return $u;
}

function expH(User $u): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($u)];
}

/** Cliente actuando como `$u` (limpia el guard JWT de la petición anterior). */
function expAs(object $test, User $u): object
{
    $token = JWTAuth::fromUser($u);
    auth()->forgetGuards();
    app('tymon.jwt')->unsetToken();
    app('tymon.jwt.auth')->unsetToken();

    return $test->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$token]);
}

/** @return list<list<string|null>> */
function expFilas(DataExport $e): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'tst').'.'.$e->format;
    file_put_contents($tmp, Storage::disk($e->disk)->get($e->path));
    $rows = iterator_to_array(SpreadsheetReader::rows($tmp, $e->format), false);
    unlink($tmp);

    return $rows;
}

test('un admin exporta usuarios a CSV con cabeceras traducidas y una fila por usuario', function () {
    $admin = expAdmin();
    User::factory()->count(3)->create();

    $res = expAs($this, $admin)->postJson('/admin/exports/users', ['format' => 'csv'])->assertStatus(202);
    $export = DataExport::findOrFail($res->json('data.id'));

    expect($export->status)->toBe('completed')->and($export->total_rows)->toBe(4);
    $rows = expFilas($export);
    expect($rows[0][1])->toBeIn(['Name', 'Nombre'])->and($rows)->toHaveCount(5);
    expect(collect($rows)->pluck(2))->toContain($admin->email);
});

test('exporta a XLSX y el archivo se lee de vuelta', function () {
    $admin = expAdmin();

    $res = expAs($this, $admin)->postJson('/admin/exports/users', ['format' => 'xlsx'])->assertStatus(202);
    $export = DataExport::findOrFail($res->json('data.id'));

    expect($export->format)->toBe('xlsx')->and(str_ends_with($export->path, '.xlsx'))->toBeTrue();
    expect(expFilas($export))->toHaveCount(2);
});

test('exporta en streaming por lotes pequeños sin perder ni repetir filas', function () {
    config(['export.chunk_size' => 2]);
    $admin = expAdmin();
    User::factory()->count(6)->create();

    $res = expAs($this, $admin)->postJson('/admin/exports/users', ['format' => 'csv'])->assertStatus(202);
    $ids = collect(expFilas(DataExport::findOrFail($res->json('data.id'))))->slice(1)->pluck(0);

    expect($ids)->toHaveCount(7)->and($ids->unique())->toHaveCount(7);
});

test('aplica los filtros del exportador y rechaza filtros inválidos con 422', function () {
    $admin = expAdmin();
    User::factory()->create(['name' => 'Zoe Unica']);

    $res = expAs($this, $admin)->postJson('/admin/exports/users', ['format' => 'csv', 'filters' => ['search' => 'Zoe Unica']])->assertStatus(202);
    expect(expFilas(DataExport::findOrFail($res->json('data.id'))))->toHaveCount(2);

    expAs($this, $admin)->postJson('/admin/exports/users', ['format' => 'csv', 'filters' => ['role' => ['x']]])->assertStatus(422);
});

test('neutraliza la inyección de fórmulas en las celdas', function () {
    expect(SpreadsheetWriter::cell('=SUM(A1)'))->toBe("'=SUM(A1)")
        ->and(SpreadsheetWriter::cell('@cmd'))->toBe("'@cmd")
        ->and(SpreadsheetWriter::cell('normal'))->toBe('normal');

    $admin = expAdmin();
    User::factory()->create(['name' => '=HYPERLINK("http://x")']);

    $res = expAs($this, $admin)->postJson('/admin/exports/users', ['format' => 'csv'])->assertStatus(202);
    expect(collect(expFilas(DataExport::findOrFail($res->json('data.id'))))->pluck(1))->toContain("'=HYPERLINK(\"http://x\")");
});

test('el dueño ve su exportación, la descarga y obtiene una URL; otro admin recibe 404', function () {
    $admin = expAdmin();
    $otro = expAdmin();

    $id = expAs($this, $admin)->postJson('/admin/exports/users', ['format' => 'csv'])->json('data.id');

    expAs($this, $admin)->getJson("/admin/exports/{$id}")->assertOk()->assertJsonPath('data.status', 'completed');
    expAs($this, $admin)->getJson('/admin/exports')->assertOk()->assertJsonCount(1, 'data');
    expAs($this, $admin)->get("/admin/exports/{$id}/download")->assertOk()->assertDownload();

    expAs($this, $otro)->getJson("/admin/exports/{$id}")->assertNotFound();
    expAs($this, $otro)->get("/admin/exports/{$id}/download")->assertNotFound();
    expAs($this, $otro)->getJson('/admin/exports')->assertJsonCount(0, 'data');
});

test('sin permiso responde 403 y sin token 401', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'user')->first());

    expAs($this, $user)->postJson('/admin/exports/users', ['format' => 'csv'])->assertForbidden();
    expAs($this, $user)->getJson('/admin/exports')->assertForbidden();

    auth()->forgetGuards();
    app('tymon.jwt')->unsetToken();
    app('tymon.jwt.auth')->unsetToken();
    $this->flushHeaders()->postJson('/admin/exports/users', ['format' => 'csv'])->assertUnauthorized();
    $this->assertDatabaseCount('data_exports', 0);
});

test('un formato no admitido responde 422', function () {
    expAs($this, expAdmin())->postJson('/admin/exports/users', ['format' => 'pdf'])->assertStatus(422);
});

test('una exportación que falla queda marcada y no se repite', function () {
    $admin = expAdmin();
    $export = DataExport::create(['user_id' => $admin->id, 'exporter' => 'users', 'format' => 'csv', 'filters' => [], 'status' => 'pending']);
    config(['export.exporters.users' => 'App\\NoExiste']);

    app(ExportServiceInterface::class)->run($export);
    expect($export->fresh()->status)->toBe('failed')->and($export->fresh()->error)->not->toBeNull();

    app(ExportServiceInterface::class)->run($export->fresh());
    expect($export->fresh()->status)->toBe('failed');
});

test('exports:prune borra las exportaciones caducadas con su archivo', function () {
    $admin = expAdmin();
    $id = expAs($this, $admin)->postJson('/admin/exports/users', ['format' => 'csv'])->json('data.id');
    $export = DataExport::findOrFail($id);
    Storage::disk($export->disk)->assertExists($export->path);

    $export->update(['expires_at' => now()->subDay()]);
    $this->artisan('exports:prune')->assertExitCode(0);

    $this->assertDatabaseMissing('data_exports', ['id' => $id]);
    Storage::disk('local')->assertMissing($export->path);
});

test('al terminar deja un aviso en la bandeja del usuario con el enlace de descarga', function () {
    $admin = expAdmin();

    $id = expAs($this, $admin)->postJson('/admin/exports/users', ['format' => 'csv'])->json('data.id');

    $aviso = InboxNotification::query()->where('user_id', $admin->id)->where('type', 'export_ready')->first();
    expect($aviso)->not->toBeNull()->and($aviso->title)->not->toBeEmpty()
        ->and((string) ($aviso->data['params']['export_id'] ?? ''))->toBe((string) $id);
});

test('el aviso respeta que el tipo esté desactivado por el usuario (no rompe la exportación)', function () {
    config(['notifications.types.export_ready.channels' => []]);
    $admin = expAdmin();

    $id = expAs($this, $admin)->postJson('/admin/exports/users', ['format' => 'csv'])->assertStatus(202)->json('data.id');

    expect(DataExport::findOrFail($id)->status)->toBe('completed');
});

test('exports:prune pasa a failed las exportaciones atascadas en processing', function () {
    config(['export.stuck_minutes' => 30]);
    $admin = expAdmin();
    $atascada = DataExport::create(['user_id' => $admin->id, 'exporter' => 'users', 'format' => 'csv', 'status' => 'processing']);
    $reciente = DataExport::create(['user_id' => $admin->id, 'exporter' => 'users', 'format' => 'csv', 'status' => 'processing']);
    DataExport::whereKey($atascada->id)->update(['updated_at' => now()->subMinutes(45)]);

    $this->artisan('exports:prune')->assertExitCode(0);

    expect($atascada->fresh()->status)->toBe('failed')->and($atascada->fresh()->error)->not->toBeNull()
        ->and($reciente->fresh()->status)->toBe('processing');
});

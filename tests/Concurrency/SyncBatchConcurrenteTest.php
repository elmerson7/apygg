<?php

use App\Contracts\OfflineSyncInterface;
use App\Contracts\OptimisticLockInterface;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Support\ConcurrentRunner;
use Tests\Support\SyncDemoItem;

/*
 * Carrera real del módulo `offline-sync`: dos lotes simultáneos con la misma clave de idempotencia crean UNA fila, y
 * dos updates con el mismo If-Match no se aplican los dos. Los datos quedan comprometidos: se limpian a mano.
 */

beforeEach(function () {
    Schema::dropIfExists('sync_demo_items');
    Schema::create('sync_demo_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->foreignId('tenant_id')->nullable();
        $table->string('title');
        $table->jsonb('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function () {
    Schema::dropIfExists('sync_demo_items');
    DB::table('idempotency_keys')->where('key', 'like', 'sync:carrera-%')->delete();
    DB::table('users')->where('email', 'like', 'carrera-sync-%')->delete();
    DB::table('permissions')->where('name', 'syncdemo.write')->delete();
});

test('dos lotes simultaneos con la misma clave y el mismo id crean una sola fila', function () {
    $user = User::factory()->create(['email' => 'carrera-sync-'.bin2hex(random_bytes(4)).'@example.com']);
    $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'syncdemo.write', 'guard_name' => 'api'], ['display_name' => 'x', 'resource' => 'syncdemo', 'action' => 'write']));
    $userId = $user->id;
    $key = 'carrera-'.bin2hex(random_bytes(4));
    $id = (string) Str::uuid7();

    $results = ConcurrentRunner::run(2, function (int $i) use ($userId, $key, $id) {
        config(['offline-sync.resources' => ['items' => [
            'model' => SyncDemoItem::class,
            'permissions' => ['viewAny' => 'syncdemo.read', 'create' => 'syncdemo.write', 'update' => 'syncdemo.write', 'delete' => 'syncdemo.write'],
            'create_rules' => ['title' => 'required|string|max:100'],
        ]]]);
        $user = User::findOrFail($userId);

        return app(OfflineSyncInterface::class)->batch($user, [[
            'idempotency_key' => $key, 'resource' => 'items', 'op' => 'create', 'id' => $id, 'data' => ['title' => 'Carrera'],
        ]])[0];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $status = collect($results)->pluck('result.status')->sort()->values()->all();

    // Una crea (201); la otra o ve la clave en curso (409) o, si llegó después, repite el 201 guardado.
    expect($status[1])->toBeIn([201, 409])
        ->and($status[0])->toBeIn([201, 409])
        ->and(DB::table('sync_demo_items')->where('id', $id)->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->where('key', 'sync:'.$key)->count())->toBe(1);
});

test('dos lotes simultaneos que actualizan con el mismo If-Match: solo uno gana y el otro recibe 412', function () {
    $user = User::factory()->create(['email' => 'carrera-sync-'.bin2hex(random_bytes(4)).'@example.com']);
    $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'syncdemo.write', 'guard_name' => 'api'], ['display_name' => 'x', 'resource' => 'syncdemo', 'action' => 'write']));
    $userId = $user->id;

    $item = SyncDemoItem::createFromClient(['title' => 'Original']);
    $etag = app(OptimisticLockInterface::class)->etag($item->refresh());
    $itemId = $item->id;
    $sufijo = bin2hex(random_bytes(4));

    $results = ConcurrentRunner::run(4, function (int $i) use ($userId, $itemId, $etag, $sufijo) {
        config(['offline-sync.resources' => ['items' => [
            'model' => SyncDemoItem::class,
            'permissions' => ['viewAny' => 'syncdemo.read', 'create' => 'syncdemo.write', 'update' => 'syncdemo.write', 'delete' => 'syncdemo.write'],
            'create_rules' => ['title' => 'required|string|max:100'],
        ]]]);

        return app(OfflineSyncInterface::class)->batch(User::findOrFail($userId), [[
            'idempotency_key' => "carrera-{$sufijo}-{$i}", 'resource' => 'items', 'op' => 'update',
            'id' => $itemId, 'data' => ['title' => "Gana {$i}"], 'if_match' => $etag,
        ]])[0];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $status = collect($results)->pluck('result.status')->countBy()->all();

    expect($status[200] ?? 0)->toBe(1, json_encode($results))
        ->and($status[412] ?? 0)->toBe(3, json_encode($results));
});

<?php

use App\Contracts\DirectUploadServiceInterface;
use App\Exceptions\StorageQuotaExceededException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConcurrentRunner;

/*
 * Carrera real de la cuota de almacenamiento: varios procesos reservan cuota a la vez (intención de subida
 * presignada) y, juntos, no pueden pasarse de `max_storage_mb`. Los datos quedan comprometidos: se limpian a mano.
 */

beforeEach(function () {
    $this->sufijo = bin2hex(random_bytes(4));
});

afterEach(function () {
    DB::table('file_upload_intents')->whereIn('tenant_id', DB::table('tenants')->where('slug', 'like', 'carrera-arch-%')->pluck('id'))->delete();
    DB::table('tenants')->where('slug', 'like', 'carrera-arch-%')->delete(); // cascada: límites y membresías
    DB::table('users')->where('email', 'like', 'carrera-arch-%@example.com')->delete();
});

test('seis reservas simultáneas de 400 KB con 1 MB de cuota: solo caben dos y el resto recibe storage_quota_exceeded', function () {
    $tenant = Tenant::create(['name' => 'Carrera', 'slug' => 'carrera-arch-'.$this->sufijo]);
    $user = User::factory()->create(['email' => "carrera-arch-{$this->sufijo}@example.com"]);
    DB::table('tenant_limit_overrides')->insert([
        'tenant_id' => $tenant->id, 'key' => 'max_storage_mb', 'value' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    [$tenantId, $userId] = [$tenant->id, $user->id];

    $results = ConcurrentRunner::run(6, function (int $i) use ($tenantId, $userId) {
        config(['multi-tenancy.enabled' => true, 'plans.enabled' => true]);
        Storage::disk('local')->buildTemporaryUploadUrlsUsing(fn ($path) => ['url' => 'https://bucket.test/'.$path, 'headers' => []]);

        try {
            app(TenantContext::class)->run(Tenant::query()->findOrFail($tenantId), function () use ($userId, $i) {
                app(DirectUploadServiceInterface::class)->create(User::query()->findOrFail($userId), [
                    'name' => "carrera-{$i}.txt", 'size' => 400 * 1024, 'mime_type' => 'text/plain',
                ]);
            });

            return 'ok';
        } catch (StorageQuotaExceededException) {
            return 'cuota';
        }
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    $resultados = array_count_values(array_column($results, 'result'));

    expect($resultados['ok'] ?? 0)->toBe(2, json_encode($results))
        ->and($resultados['cuota'] ?? 0)->toBe(4, json_encode($results))
        ->and((int) DB::table('file_upload_intents')->where('tenant_id', $tenantId)->sum('size'))->toBe(2 * 400 * 1024);
});

test('sin límites activos las reservas simultáneas no se bloquean ni se rechazan', function () {
    $tenant = Tenant::create(['name' => 'Libre', 'slug' => 'carrera-arch-libre-'.$this->sufijo]);
    $user = User::factory()->create(['email' => "carrera-arch-libre-{$this->sufijo}@example.com"]);
    [$tenantId, $userId] = [$tenant->id, $user->id];

    $results = ConcurrentRunner::run(4, function (int $i) use ($tenantId, $userId) {
        config(['multi-tenancy.enabled' => true, 'plans.enabled' => false]);
        Storage::disk('local')->buildTemporaryUploadUrlsUsing(fn ($path) => ['url' => 'https://bucket.test/'.$path, 'headers' => []]);

        app(TenantContext::class)->run(Tenant::query()->findOrFail($tenantId), function () use ($userId, $i) {
            app(DirectUploadServiceInterface::class)->create(User::query()->findOrFail($userId), [
                'name' => "libre-{$i}.txt", 'size' => 400 * 1024, 'mime_type' => 'text/plain',
            ]);
        });

        return 'ok';
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(DB::table('file_upload_intents')->where('tenant_id', $tenantId)->count())->toBe(4);
});

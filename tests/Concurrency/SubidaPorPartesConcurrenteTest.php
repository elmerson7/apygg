<?php

use App\Contracts\DirectUploadServiceInterface;
use App\Contracts\MultipartStorageInterface;
use App\Exceptions\StorageQuotaExceededException;
use App\Models\FileUploadIntent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConcurrentRunner;
use Tests\Support\FakeMultipartStorage;

/*
 * Carreras reales de la subida por partes: dos "complete" simultáneos de la misma intención y varias
 * reservas simultáneas contra la cuota. Los datos quedan comprometidos: se limpian a mano.
 */

beforeEach(function () {
    $this->sufijo = bin2hex(random_bytes(4));
});

afterEach(function () {
    $tenants = DB::table('tenants')->where('slug', 'like', 'carrera-mp-%')->pluck('id');
    DB::table('files')->whereIn('tenant_id', $tenants)->delete();
    DB::table('file_upload_intents')->whereIn('tenant_id', $tenants)->delete(); // cascada: file_multipart_uploads
    DB::table('tenants')->where('slug', 'like', 'carrera-mp-%')->delete();
    DB::table('users')->where('email', 'like', 'carrera-mp-%@example.com')->delete();
    Storage::disk('local')->deleteDirectory('carrera-mp');
});

test('dos complete simultáneos de la misma subida por partes crean un solo archivo y devuelven el mismo', function () {
    $tenant = Tenant::create(['name' => 'Carrera MP', 'slug' => 'carrera-mp-'.$this->sufijo]);
    $user = User::factory()->create(['email' => "carrera-mp-{$this->sufijo}@example.com"]);
    [$tenantId, $userId] = [$tenant->id, $user->id];

    // Prepara la intención en el padre (con el fake sobre el disco real) y sube las tres partes.
    $fake = new FakeMultipartStorage;
    config(['multi-tenancy.enabled' => true, 'plans.enabled' => false, 'files.multipart.part_size_mb' => 5, 'files.prefix' => 'carrera-mp']);
    app()->instance(MultipartStorageInterface::class, $fake);
    $size = 2 * 5 * 1048576 + 1048576;
    $uuid = app(TenantContext::class)->run($tenant, function () use ($user, $size) {
        return app(DirectUploadServiceInterface::class)->createMultipart($user, ['name' => 'g.txt', 'size' => $size, 'mime_type' => 'text/plain'])['intent']->uuid;
    });
    [$intent, $mp] = TenantQuery::withoutTenant(fn () => [$i = FileUploadIntent::where('uuid', $uuid)->firstOrFail(), $i->multipart], 'test');
    foreach ([1, 2, 3] as $n) {
        $fake->putPart($intent->disk, $intent->path, $mp->upload_id, $n, str_repeat('a', $n < 3 ? $mp->part_size : $size - 2 * $mp->part_size));
    }

    $results = ConcurrentRunner::run(2, function (int $i) use ($tenantId, $uuid) {
        config(['multi-tenancy.enabled' => true, 'plans.enabled' => false, 'files.prefix' => 'carrera-mp']);
        app()->instance(MultipartStorageInterface::class, new FakeMultipartStorage);

        return app(TenantContext::class)->run(Tenant::query()->findOrFail($tenantId), function () use ($uuid) {
            $uploads = app(DirectUploadServiceInterface::class);

            return $uploads->complete($uploads->find($uuid))->id;
        });
    }, 90);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect($results[0]['result'])->toBe($results[1]['result'])
        ->and(DB::table('files')->where('tenant_id', $tenantId)->count())->toBe(1)
        ->and(DB::table('file_upload_intents')->where('tenant_id', $tenantId)->value('status'))->toBe('completed');
});

test('seis reservas multipart simultáneas de 400 KB con 1 MB de cuota: solo caben dos', function () {
    $tenant = Tenant::create(['name' => 'Carrera MP', 'slug' => 'carrera-mp-q-'.$this->sufijo]);
    $user = User::factory()->create(['email' => "carrera-mp-q-{$this->sufijo}@example.com"]);
    DB::table('tenant_limit_overrides')->insert([
        'tenant_id' => $tenant->id, 'key' => 'max_storage_mb', 'value' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    [$tenantId, $userId] = [$tenant->id, $user->id];

    $results = ConcurrentRunner::run(6, function (int $i) use ($tenantId, $userId) {
        config(['multi-tenancy.enabled' => true, 'plans.enabled' => true, 'files.prefix' => 'carrera-mp']);
        app()->instance(MultipartStorageInterface::class, new FakeMultipartStorage);

        try {
            app(TenantContext::class)->run(Tenant::query()->findOrFail($tenantId), function () use ($userId, $i) {
                app(DirectUploadServiceInterface::class)->createMultipart(User::query()->findOrFail($userId), [
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
        ->and((int) DB::table('file_upload_intents')->where('tenant_id', $tenantId)->sum('size'))->toBe(2 * 400 * 1024)
        ->and(DB::table('file_multipart_uploads')->whereIn('file_upload_intent_id', DB::table('file_upload_intents')->where('tenant_id', $tenantId)->pluck('id'))->count())->toBe(2);
});

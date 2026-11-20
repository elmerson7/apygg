<?php

use App\Contracts\AccountDeletionServiceInterface;
use App\Contracts\PersonalDataExportServiceInterface;
use App\Models\AccountDeletionRequest;
use App\Models\File;
use App\Models\InboxNotification;
use App\Models\PersonalDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    $this->bootIsolation();
    Storage::fake('local');
    Mail::fake();
    config(['queue.default' => 'sync', 'privacy.export.disk' => 'local']);
    // La MISMA persona en A y en B, y otra persona solo en B.
    $this->user = $this->isoUser($this->a);
    $this->b->users()->attach($this->user->getKey(), ['status' => 'active', 'role' => 'member', 'joined_at' => now()]);
    $this->otro = $this->isoUser($this->b);
});

test('la exportación incluye lo del usuario en todos sus tenants y nada de otras personas ni de otros tenants', function () {
    $now = now();
    foreach ([[$this->a, $this->user, 'mio-en-a'], [$this->b, $this->user, 'mio-en-b'], [$this->b, $this->otro, 'ajeno-en-b']] as [$tenant, $owner, $title]) {
        DB::table('inbox_notifications')->insert(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'type' => 'x', 'title' => $title, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('files')->insert(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'name' => $title, 'filename' => "$title.txt", 'path' => "u/$title", 'disk' => 'local', 'mime_type' => 'text/plain', 'extension' => 'txt', 'size' => 1, 'type' => 'document', 'created_at' => $now, 'updated_at' => $now]);
    }

    $export = app(PersonalDataExportServiceInterface::class)->request($this->user);
    $export = PersonalDataExport::query()->findOrFail($export->id);

    $tmp = tempnam(sys_get_temp_dir(), 'z');
    file_put_contents($tmp, Storage::disk('local')->get($export->path));
    $zip = new ZipArchive;
    $zip->open($tmp);
    $notifs = json_decode($zip->getFromName('notifications.json'), true);
    $files = json_decode($zip->getFromName('files.json'), true);
    $members = json_decode($zip->getFromName('tenant_memberships.json'), true);
    $zip->close();
    unlink($tmp);

    expect(array_column($notifs['inbox'], 'title'))->toEqualCanonicalizing(['mio-en-a', 'mio-en-b'])
        ->and(array_column($files['files'], 'name'))->toEqualCanonicalizing(['mio-en-a', 'mio-en-b'])
        ->and(array_column($members['memberships'], 'slug'))->toEqualCanonicalizing(['acme', 'beta']);
});

test('borrar la cuenta de un miembro no toca lo que otro usuario tiene en el mismo tenant', function () {
    $now = now();
    DB::table('inbox_notifications')->insert(['tenant_id' => $this->b->id, 'user_id' => $this->otro->id, 'type' => 'x', 'title' => 'ajeno', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('inbox_notifications')->insert(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'type' => 'x', 'title' => 'mio', 'created_at' => $now, 'updated_at' => $now]);

    // El usuario es miembro raso (no dueño) en A y B: puede borrarse.
    $svc = app(AccountDeletionServiceInterface::class);
    $svc->request($this->user, 'password', null, null);
    $this->travel(15)->days();
    $svc->execute(AccountDeletionRequest::query()->firstOrFail()->id);

    $this->ctx->run($this->b, fn () => expect(InboxNotification::query()->pluck('title')->all())->toBe(['ajeno']));
    $this->ctx->run($this->a, fn () => expect(InboxNotification::query()->count())->toBe(0));
    expect(File::query()->withoutGlobalScopes()->count())->toBe(0);
});

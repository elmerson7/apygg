<?php

use App\Contracts\SequenceInterface;
use App\Models\File;
use App\Models\InboxNotification;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SeedLoad\LoadDataGenerator;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

/**
 * Datos masivos (`seed:load`, docs/seed-load.md): tamaño pequeño en la suite, invariantes completas.
 */
function cargar(int $tenants = 3, int $users = 4, int $seed = 11, int $batch = 100, int $files = 3): void
{
    test()->artisan('seed:load', [
        '--tenants' => $tenants,
        '--users-per-tenant' => $users,
        '--files-per-tenant' => $files,
        '--seed' => $seed,
        '--as-of' => '2026-11-20T12:00:00+00:00',
        '--batch' => $batch,
        '--credentials-limit' => 0,
    ])->assertSuccessful();
}

/**
 * @return list<array<string, mixed>>
 */
function huella(bool $conNumeros = true): array
{
    return DB::table('tenants')->where('slug', 'like', LoadDataGenerator::SLUG_PREFIX.'%')->orderBy('slug')->get()->map(function (object $t) use ($conNumeros): array {
        $invoices = DB::table('invoices')->where('tenant_id', $t->id)->orderBy('period_start')->orderBy('id')
            ->get(['billing_reason', 'status', 'total_minor', 'tax_minor', 'currency', 'period_start', 'number'])
            ->map(fn (object $i) => (array) $i)->all();

        if (! $conNumeros) {
            $invoices = array_map(fn (array $i) => array_diff_key($i, ['number' => 0]), $invoices);
        }

        return [
            'slug' => $t->slug, 'name' => $t->name, 'plan' => $t->plan, 'status' => $t->status, 'created_at' => $t->created_at,
            'users' => DB::table('tenant_user')->where('tenant_id', $t->id)->orderBy('role')->orderBy('joined_at')->pluck('role')->all(),
            'sub' => (array) DB::table('subscriptions')->where('tenant_id', $t->id)->first(['status', 'plan_code', 'interval', 'currency', 'unit_amount_minor', 'current_period_start', 'current_period_end']),
            'invoices' => $invoices,
            'files' => DB::table('files')->where('tenant_id', $t->id)->count(),
        ];
    })->all();
}

beforeEach(function () {
    config(['billing.tax.rate' => '18']);
});

it('genera las cantidades pedidas con un unico dueño por empresa y roles coherentes', function () {
    cargar(tenants: 3, users: 5);

    expect(DB::table('tenants')->where('slug', 'like', 'ld-%')->count())->toBe(3)
        ->and(DB::table('users')->where('email', 'like', '%@seed-load.test')->count())->toBe(15);

    foreach (DB::table('tenants')->where('slug', 'like', 'ld-%')->pluck('id') as $tenantId) {
        $roles = DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', '>', 0)->get();
        // 5 usuarios propios (+ algún miembro de otra empresa del lote)
        expect($roles->where('role', 'owner')->count())->toBe(1)
            ->and($roles->where('role', 'owner')->first()->status)->toBe('active')
            ->and($roles->where('role', 'admin')->count())->toBeGreaterThanOrEqual(1);
    }

    // Cada miembro tiene el rol de Spatie de su rol en la empresa.
    $owners = DB::table('tenant_user')->where('role', 'owner')->pluck('user_id');
    $roleId = DB::table('roles')->where('name', 'tenant-owner')->value('id');
    expect(DB::table('model_has_roles')->where('role_id', $roleId)->whereIn('model_id', $owners)->count())->toBe($owners->count());

    $user = User::query()->where('email', 'like', 'u1.ld-%@seed-load.test')->first();
    expect($user)->not->toBeNull()
        ->and(password_verify('password', $user->password))->toBeTrue()
        ->and($user->hasRole('user'))->toBeTrue();
});

it('mantiene la coherencia de facturas, lineas y pagos (Money entero, total = subtotal + impuesto)', function () {
    cargar(tenants: 40, users: 3);

    $invoices = DB::table('invoices')->get();
    expect($invoices->count())->toBeGreaterThan(20);

    foreach ($invoices as $i) {
        expect($i->total_minor)->toBe($i->subtotal_minor + $i->tax_minor)
            ->and($i->tax_minor)->toBe((int) round($i->subtotal_minor * 0.18, 0, PHP_ROUND_HALF_UP))
            ->and($i->tax_rate)->toBe('18');

        $lines = DB::table('invoice_lines')->where('invoice_id', $i->id)->get();
        expect($lines)->toHaveCount(1)
            ->and($lines->sum('amount_minor'))->toBe($i->subtotal_minor)
            ->and($lines->first()->amount_minor)->toBe($lines->first()->quantity * $lines->first()->unit_amount_minor);

        $payments = DB::table('payments')->where('invoice_id', $i->id)->get();
        $ok = $payments->where('status', 'succeeded');

        if ($i->status === 'paid') {
            expect($ok)->toHaveCount(1)
                ->and($ok->first()->amount_minor)->toBe($i->total_minor)
                ->and($ok->first()->currency)->toBe($i->currency)
                ->and($i->paid_at)->not->toBeNull();
        } else {
            expect($ok)->toHaveCount(0)->and($i->paid_at)->toBeNull();
        }

        expect($payments->pluck('tenant_id')->unique()->all())->toBe([$i->tenant_id]);
    }

    // Una suscripcion por empresa como maximo, con el precio congelado del catalogo y facturas de SU empresa.
    foreach (DB::table('subscriptions')->get() as $s) {
        $price = DB::table('plan_prices')->where('id', $s->plan_price_id)->first();
        expect($price->unit_amount_minor)->toBe($s->unit_amount_minor)
            ->and($price->currency)->toBe($s->currency)
            ->and(DB::table('invoices')->where('subscription_id', $s->id)->where('tenant_id', '!=', $s->tenant_id)->count())->toBe(0);
    }
    expect(DB::table('subscriptions')->select('tenant_id')->groupBy('tenant_id')->havingRaw('count(*) > 1')->count())->toBe(0);
});

it('numera las facturas sin huecos ni repetidos por serie global y periodo', function () {
    cargar(tenants: 60, users: 2, batch: 25);

    $porPeriodo = DB::table('invoices')->get()->groupBy(fn (object $i) => substr($i->number, 2, 4));
    expect($porPeriodo)->not->toBeEmpty();

    foreach ($porPeriodo as $year => $invoices) {
        $numeros = $invoices->map(fn (object $i) => (int) substr($i->number, -6))->sort()->values()->all();
        expect($numeros)->toBe(range(1, count($numeros)))
            ->and($invoices->pluck('number_scope')->unique()->all())->toBe(['global']);

        // El contador de la secuencia apunta justo al siguiente.
        expect((int) DB::table('sequences')->whereNull('tenant_id')->where('name', 'billing_invoice')->where('period', (string) $year)->value('next_value'))->toBe(count($numeros) + 1);
    }
});

it('numera sin huecos por empresa cuando la serie es por tenant', function () {
    config(['billing.invoice.sequence_scope' => 'tenant']);
    cargar(tenants: 20, users: 2, batch: 7);

    foreach (DB::table('invoices')->get()->groupBy(fn (object $i) => $i->tenant_id.'|'.substr($i->number, 2, 4)) as $key => $invoices) {
        $numeros = $invoices->map(fn (object $i) => (int) substr($i->number, -6))->sort()->values()->all();
        expect($numeros)->toBe(range(1, count($numeros)), "empresa|año {$key}")
            ->and($invoices->pluck('number_scope')->unique()->all())->toBe(['tenant']);
    }
});

it('no mezcla datos entre empresas con el aislamiento activo', function () {
    cargar(tenants: 4, users: 4);
    $this->enableTenancy();
    $ctx = app(TenantContext::class);

    $tenants = Tenant::query()->where('slug', 'like', 'ld-%')->get();

    foreach ($tenants as $tenant) {
        $ctx->run($tenant, function () use ($tenant): void {
            foreach ([Invoice::class, Subscription::class, File::class, InboxNotification::class] as $model) {
                $rows = $model::query()->get();
                expect($rows->pluck('tenant_id')->unique()->all())->toBeIn([[], [$tenant->id]])
                    ->and($rows->count())->toBe(DB::table((new $model)->getTable())->where('tenant_id', $tenant->id)->count());
            }

            // Los archivos subidos son de miembros de la propia empresa.
            $miembros = DB::table('tenant_user')->where('tenant_id', $tenant->id)->pluck('user_id')->all();
            expect(File::query()->pluck('user_id')->diff($miembros)->all())->toBe([]);
        });
    }

    $propias = DB::table('invoices')->count();
    $vistas = $tenants->sum(fn (Tenant $t) => $ctx->run($t, fn () => Invoice::query()->count()));
    expect($vistas)->toBe($propias);
});

it('genera lo mismo con la misma semilla aunque cambie el tamaño del lote', function () {
    cargar(tenants: 12, users: 3, seed: 5, batch: 100);
    $a = huella(conNumeros: false);

    $this->artisan('seed:load:purge', ['--force' => true])->assertSuccessful();
    expect(DB::table('tenants')->where('slug', 'like', 'ld-%')->count())->toBe(0);

    cargar(tenants: 12, users: 3, seed: 5, batch: 5);
    expect(huella(conNumeros: false))->toBe($a);

    // Y con la misma partición del lote, hasta los numeros de factura coinciden.
    $this->artisan('seed:load:purge', ['--force' => true])->assertSuccessful();
    cargar(tenants: 12, users: 3, seed: 5, batch: 5);
    $b = huella();
    $this->artisan('seed:load:purge', ['--force' => true])->assertSuccessful();
    cargar(tenants: 12, users: 3, seed: 5, batch: 5);
    expect(huella())->toBe($b);

    // Otra semilla, otros datos.
    $this->artisan('seed:load:purge', ['--force' => true])->assertSuccessful();
    cargar(tenants: 12, users: 3, seed: 6, batch: 5);
    expect(huella(conNumeros: false))->not->toBe($a);
});

it('continua la numeracion de empresas al cargar otra vez y la serie real sigue sin huecos', function () {
    cargar(tenants: 5, users: 2);
    cargar(tenants: 5, users: 2, seed: 12);

    expect(DB::table('tenants')->where('slug', 'like', 'ld-%')->count())->toBe(10)
        ->and(DB::table('tenants')->where('slug', 'ld-0000010')->exists())->toBeTrue();

    // Una factura "real" posterior toma el siguiente numero de la serie global.
    $year = now()->format('Y');
    $max = DB::table('invoices')->where('number', 'like', "F-{$year}-%")->get()->map(fn (object $i) => (int) substr($i->number, -6))->max();
    $next = TenantQuery::withoutTenant(fn () => app(SequenceInterface::class)->next('billing_invoice'), 'prueba');
    expect($next)->toBe(sprintf('F-%s-%06d', $year, $max + 1));
});

it('borra solo lo generado y devuelve la serie global a su sitio', function () {
    $ajeno = $this->makeTenant('empresa-real');
    $usuario = User::factory()->create(['email' => 'real@example.com']);
    $this->addMember($ajeno, $usuario);

    cargar(tenants: 8, users: 3);
    expect(DB::table('invoices')->count())->toBeGreaterThan(0);

    $this->artisan('seed:load:purge', ['--force' => true])->assertSuccessful();

    expect(DB::table('tenants')->where('slug', 'like', 'ld-%')->count())->toBe(0)
        ->and(DB::table('users')->where('email', 'like', '%@seed-load.test')->count())->toBe(0)
        ->and(DB::table('invoices')->count())->toBe(0)
        ->and(DB::table('subscriptions')->count())->toBe(0)
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('files')->count())->toBe(0)
        ->and(DB::table('inbox_notifications')->count())->toBe(0)
        ->and(DB::table('sequences')->where('name', 'billing_invoice')->count())->toBe(0)
        ->and(DB::table('model_has_roles')->whereNotIn('model_id', DB::table('users')->select('id'))->where('model_type', (new User)->getMorphClass())->count())->toBe(0)
        ->and(Tenant::query()->where('slug', 'empresa-real')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'real@example.com')->exists())->toBeTrue()
        ->and(DB::table('tenant_user')->where('tenant_id', $ajeno->id)->count())->toBe(1);
});

it('se niega a correr en produccion', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('seed:load', ['--tenants' => 1])->assertFailed();
    $this->artisan('seed:load:purge', ['--force' => true])->assertFailed();

    expect(DB::table('tenants')->where('slug', 'like', 'ld-%')->count())->toBe(0);
});

it('escribe credenciales de ejemplo para las pruebas de carga', function () {
    $path = sys_get_temp_dir().'/seed-load-creds-'.uniqid().'.csv';

    $this->artisan('seed:load', ['--tenants' => 3, '--users-per-tenant' => 3, '--files-per-tenant' => 0, '--credentials' => $path, '--credentials-limit' => 2])->assertSuccessful();

    $lines = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES));
    @unlink($path);

    expect($lines[0])->toBe(['tenant_id', 'tenant_slug', 'email', 'password', 'tenant_role'])
        ->and(count($lines))->toBeGreaterThan(2)
        ->and($lines[1][3])->toBe('password')
        ->and($lines[1][4])->toBe('owner');
});

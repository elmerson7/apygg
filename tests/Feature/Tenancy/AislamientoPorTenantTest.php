<?php

use App\Services\CacheService;
use App\Support\Tenancy\Exceptions\CrossTenantViolation;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithTenancy;
use Tests\Support\TenantNote;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

beforeEach(function () {
    $this->enableTenancy();
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->ctx = app(TenantContext::class);

    $this->noteA = $this->ctx->run($this->a, fn () => TenantNote::create(['title' => 'nota A']));
    $this->noteB = $this->ctx->run($this->b, fn () => TenantNote::create(['title' => 'nota B']));
});

test('con el contexto de A solo se ven los registros de A', function () {
    $titles = $this->ctx->run($this->a, fn () => TenantNote::query()->pluck('title')->all());

    expect($titles)->toBe(['nota A']);
});

test('con el contexto de A, find de un registro de B devuelve null', function () {
    $found = $this->ctx->run($this->a, fn () => TenantNote::find($this->noteB->id));

    expect($found)->toBeNull();
});

test('con el contexto de A no se puede actualizar por consulta un registro de B', function () {
    $rows = $this->ctx->run($this->a, fn () => TenantNote::query()->whereKey($this->noteB->id)->update(['title' => 'hackeada']));

    expect($rows)->toBe(0)
        ->and(TenantQuery::withoutTenant(fn () => TenantNote::find($this->noteB->id)->title))->toBe('nota B');
});

test('con el contexto de A no se puede borrar un registro de B', function () {
    $rows = $this->ctx->run($this->a, fn () => TenantNote::query()->whereKey($this->noteB->id)->delete());

    expect($rows)->toBe(0)
        ->and(TenantQuery::withoutTenant(fn () => TenantNote::query()->count()))->toBe(2);
});

test('un modelo de B obtenido sin scopes no se puede actualizar ni borrar desde A', function () {
    $this->ctx->run($this->a, function () {
        $foreign = TenantNote::query()->withoutGlobalScopes()->whereKey($this->noteB->id)->first();
    });
})->throws(CrossTenantViolation::class);

test('un modelo de B conservado en memoria no se puede actualizar desde A', function () {
    $this->ctx->run($this->a, function () {
        $this->noteB->title = 'cambio';
        $this->noteB->save();
    });
})->throws(CrossTenantViolation::class);

test('un modelo de B conservado en memoria no se puede borrar desde A', function () {
    $this->ctx->run($this->a, fn () => $this->noteB->delete());
})->throws(CrossTenantViolation::class);

test('sin contexto y con multi-tenancy activo consultar lanza MissingTenantContext', function () {
    TenantNote::query()->get();
})->throws(MissingTenantContext::class);

test('sin contexto y con multi-tenancy activo crear lanza MissingTenantContext', function () {
    TenantNote::create(['title' => 'huérfana']);
})->throws(MissingTenantContext::class);

test('al crear se rellena el tenant_id del contexto', function () {
    $note = $this->ctx->run($this->a, fn () => TenantNote::create(['title' => 'nueva']));

    expect($note->tenant_id)->toBe($this->a->id);
});

test('crear con el tenant_id de B desde A se rechaza', function () {
    $this->ctx->run($this->a, fn () => TenantNote::create(['title' => 'x', 'tenant_id' => $this->b->id]));
})->throws(CrossTenantViolation::class);

test('cambiar el tenant_id de un registro existente se rechaza', function () {
    $this->ctx->run($this->a, function () {
        $note = TenantNote::first();
        $note->tenant_id = $this->b->id;
        $note->save();
    });
})->throws(CrossTenantViolation::class);

test('cambiar el tenant_id se rechaza también dentro de withoutTenant', function () {
    TenantQuery::withoutTenant(function () {
        $note = TenantNote::find($this->noteA->id);
        $note->tenant_id = $this->b->id;
        $note->save();
    });
})->throws(CrossTenantViolation::class);

test('withoutTenant ve todos los tenants y restaura el estado aunque falle', function () {
    $all = TenantQuery::withoutTenant(fn () => TenantNote::query()->count());

    expect($all)->toBe(2);

    expect(fn () => TenantQuery::withoutTenant(fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect(fn () => TenantNote::query()->get())->toThrow(MissingTenantContext::class);
});

test('withoutTenant puede crear con un tenant_id explícito', function () {
    $note = TenantQuery::withoutTenant(fn () => TenantNote::create(['title' => 'plataforma', 'tenant_id' => $this->b->id]));

    expect($note->tenant_id)->toBe($this->b->id);
});

test('withoutTenant sin tenant_id ni contexto no crea registros huérfanos', function () {
    TenantQuery::withoutTenant(fn () => TenantNote::create(['title' => 'sin dueño']));
})->throws(MissingTenantContext::class);

test('run restaura el tenant anterior y nunca deja el de dentro', function () {
    $this->ctx->set($this->a);

    $inside = $this->ctx->run($this->b, fn () => $this->ctx->id());

    expect($inside)->toBe($this->b->id)->and($this->ctx->id())->toBe($this->a->id);

    $this->ctx->clear();
    $this->ctx->run($this->b, fn () => null);

    expect($this->ctx->current())->toBeNull();
});

test('require lanza MissingTenantContext sin tenant', function () {
    $this->ctx->require();
})->throws(MissingTenantContext::class);

test('con multi-tenancy desactivado el modelo funciona sin contexto y sin filtrar', function () {
    config(['multi-tenancy.enabled' => false]);

    expect(TenantNote::query()->count())->toBe(2);

    $note = TenantNote::create(['title' => 'libre', 'tenant_id' => $this->a->id]);
    $note->tenant_id = $this->b->id;
    $note->save();

    expect($note->fresh()->tenant_id)->toBe($this->b->id);
});

test('la caché prefija las claves con el tenant activo y no las mezcla', function () {
    CacheService::set('clave', 'de-A');
    expect(CacheService::get('clave'))->toBe('de-A');

    $this->ctx->run($this->a, function () {
        CacheService::set('clave', 'tenant-A');
    });
    $this->ctx->run($this->b, function () {
        expect(CacheService::get('clave'))->toBeNull();
        CacheService::set('clave', 'tenant-B');
    });

    expect($this->ctx->run($this->a, fn () => CacheService::get('clave')))->toBe('tenant-A')
        ->and(CacheService::get('clave'))->toBe('de-A');
});

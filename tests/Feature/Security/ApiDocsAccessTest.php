<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * El acceso abierto a la documentación debe ser una decisión explícita, no un
 * efecto secundario del nombre del entorno: 'dev' es una etiqueta arbitraria
 * que puede acabar en un servidor accesible desde internet.
 */
beforeEach(function () {
    $this->setEnv = function (string $env, bool $publicDocs): void {
        app()->detectEnvironment(fn () => $env);
        config()->set('scramble.public_docs', $publicDocs);
    };
});

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

test('un anónimo accede en desarrollo solo si se activó a propósito', function () {
    ($this->setEnv)('dev', true);

    expect(Gate::forUser(null)->allows('viewApiDocs'))->toBeTrue();
});

test('un anónimo NO accede en desarrollo si no se activó', function () {
    ($this->setEnv)('dev', false);

    expect(Gate::forUser(null)->allows('viewApiDocs'))->toBeFalse();
});

test('en producción no hay acceso anónimo ni activando la variable', function () {
    ($this->setEnv)('production', true);

    expect(Gate::forUser(null)->allows('viewApiDocs'))->toBeFalse();
});

test('un usuario normal nunca accede', function () {
    ($this->setEnv)('production', false);

    expect(Gate::forUser(User::factory()->create())->allows('viewApiDocs'))->toBeFalse();
});

test('un administrador accede siempre', function () {
    ($this->setEnv)('production', false);

    expect(Gate::forUser(User::factory()->admin()->create())->allows('viewApiDocs'))->toBeTrue();
});

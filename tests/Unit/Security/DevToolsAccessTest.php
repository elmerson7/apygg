<?php

use App\Helpers\DevToolsAccess;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('con APP_ENV=dev nadie anónimo entra a Horizon ni a Telescope', function () {
    app()['env'] = 'dev';

    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewTelescope'))->toBeFalse();
});

test('un usuario normal no entra a Horizon ni a Telescope fuera de local', function () {
    app()['env'] = 'production';
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('viewTelescope'))->toBeFalse();
});

test('un admin entra a Horizon y Telescope fuera de local', function () {
    app()['env'] = 'dev';
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewTelescope'))->toBeTrue();
});

test('un email de la lista configurada entra aunque no sea admin', function () {
    app()['env'] = 'dev';
    $user = User::factory()->create(['email' => 'ops@example.com']);
    config(['app.devtools_allowed_emails' => ['ops@example.com']]);

    expect(DevToolsAccess::allows($user))->toBeTrue();
});

test('en local queda abierto', function () {
    app()['env'] = 'local';

    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeTrue();
});

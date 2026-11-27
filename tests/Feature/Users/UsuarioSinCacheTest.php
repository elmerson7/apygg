<?php

use App\Contracts\UserServiceInterface;
use App\Models\User;
use App\Services\CacheService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// H7: find() no guarda el usuario (con su hash) en la caché ni devuelve roles desactualizados.

beforeEach(function () {
    config(['cache.default' => 'array']);
    $this->seed(RoleSeeder::class);
});

test('find no guarda el usuario en la caché', function () {
    $user = User::factory()->create();

    app(UserServiceInterface::class)->find((string) $user->id);

    expect(CacheService::has('user:'.$user->id))->toBeFalse();
});

test('find refleja al momento un cambio de rol hecho fuera del servicio', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $service = app(UserServiceInterface::class);

    expect($service->find((string) $user->id)->isAdmin())->toBeTrue();

    $user->removeRole('admin');

    expect($service->find((string) $user->id)->isAdmin())->toBeFalse();
});

<?php

use App\Auth\JwtBlacklistStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPOpenSourceSaver\JWTAuth\Contracts\Providers\Storage;

beforeEach(function () {
    config([
        'cache.stores.jwt_prueba' => ['driver' => 'array', 'serialize' => false],
        'jwt.blacklist_store' => 'jwt_prueba',
    ]);
    app()->forgetInstance('tymon.jwt.provider.storage');
    app()->forgetInstance('tymon.jwt.blacklist');
});

test('la lista negra de JWT usa el almacén propio configurado', function () {
    $storage = app(Storage::class);

    expect($storage)->toBeInstanceOf(JwtBlacklistStorage::class);

    $storage->add('jti-revocado', ['valid_until' => time() + 60], 1);

    expect(Cache::store('jwt_prueba')->tags('tymon.jwt')->get('jti-revocado'))->not->toBeNull()
        ->and(Cache::store()->tags('tymon.jwt')->get('jti-revocado'))->toBeNull();
});

test('un cache:clear de la caché general no vacía la lista negra de JWT', function () {
    app(Storage::class)->add('jti-revocado', ['valid_until' => time() + 60], 1);

    Artisan::call('cache:clear');
    Cache::flush();

    expect(app(Storage::class)->get('jti-revocado'))->not->toBeNull();
});

test('sin store propio configurado usa el store por defecto de la caché', function () {
    config(['jwt.blacklist_store' => null]);
    app()->forgetInstance('tymon.jwt.provider.storage');

    app(Storage::class)->add('jti-x', ['valid_until' => time() + 60], 1);

    expect(Cache::store()->tags('tymon.jwt')->get('jti-x'))->not->toBeNull();

    Cache::flush();

    expect(app(Storage::class)->get('jti-x'))->toBeNull();
});

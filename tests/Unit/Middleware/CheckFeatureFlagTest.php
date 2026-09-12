<?php

use App\Helpers\Feature;
use App\Http\Middleware\CheckFeatureFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

beforeEach(function () {
    // El helper Feature cachea en memoria estática entre tests
    (new ReflectionProperty(Feature::class, 'cache'))->setValue(null, []);
});

test('deja pasar cuando la feature está habilitada', function () {
    config()->set('features.demo_feature', ['enabled' => true]);

    $response = (new CheckFeatureFlag)->handle(
        Request::create('/demo', 'GET'),
        fn () => new Response('ok'),
        'demo_feature'
    );

    expect($response->getStatusCode())->toBe(200);
});

test('responde 403 cuando la feature está deshabilitada', function () {
    config()->set('features.demo_feature', ['enabled' => false]);

    $response = (new CheckFeatureFlag)->handle(
        Request::create('/demo', 'GET'),
        fn () => new Response('ok'),
        'demo_feature'
    );

    expect($response->getStatusCode())->toBe(403);

    $payload = json_decode($response->getContent(), true);

    expect($payload['success'])->toBeFalse()
        ->and($payload['type'])->toBe('feature_disabled')
        ->and($payload['errors']['feature'])->toBe('demo_feature');
});

test('responde 403 cuando la feature no existe', function () {
    $response = (new CheckFeatureFlag)->handle(
        Request::create('/demo', 'GET'),
        fn () => new Response('ok'),
        'feature_inexistente'
    );

    expect($response->getStatusCode())->toBe(403);
});

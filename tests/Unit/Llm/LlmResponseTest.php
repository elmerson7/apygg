<?php

use App\Services\LLM\LlmResponse;

test('una respuesta correcta suma los tokens', function () {
    $response = new LlmResponse(content: 'hola', model: 'x', promptTokens: 10, completionTokens: 5);

    expect($response->isOk())->toBeTrue()
        ->and($response->isUnavailable())->toBeFalse()
        ->and($response->totalTokens())->toBe(15);
});

test('skipped produce una respuesta fallida con motivo', function () {
    $response = LlmResponse::skipped('sin credenciales');

    expect($response->isOk())->toBeFalse()
        ->and($response->error)->toBe('sin credenciales')
        ->and($response->skipReason)->toBe(LlmResponse::SKIP_NOT_CONFIGURED)
        ->and($response->content)->toBe('');
});

test('no configurado, flag apagado y circuito abierto son "no disponible", no incidentes', function () {
    foreach ([
        LlmResponse::SKIP_NOT_CONFIGURED,
        LlmResponse::SKIP_FLAG_DISABLED,
        LlmResponse::SKIP_CIRCUIT_OPEN,
    ] as $reason) {
        expect(LlmResponse::skipped('x', $reason)->isUnavailable())->toBeTrue();
    }
});

test('un fallo del proveedor sí es un incidente', function () {
    $response = LlmResponse::skipped('timeout', LlmResponse::SKIP_PROVIDER_ERROR);

    expect($response->isUnavailable())->toBeFalse()
        ->and($response->isOk())->toBeFalse();
});

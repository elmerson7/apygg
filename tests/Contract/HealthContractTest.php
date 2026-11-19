<?php

/*
 * Tests de contrato: fijan la FORMA pública de la API (estructura del envelope,
 * tipos, códigos) para detectar rupturas que romperían a los clientes.
 * No prueban lógica de negocio. Se corren con: make test-contract
 */

test('el health check mantiene el contrato público', function () {
    $response = $this->getJson('/health');

    $response->assertOk()->assertJsonStructure(['status']);
    expect($response->json('status'))->toBeString();
});

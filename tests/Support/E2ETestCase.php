<?php

namespace Tests\Support;

use Tests\TestCase;

/**
 * Base de la suite E2E: habla con un stack levantado por HTTP, así que no toca la BD de
 * tests (no siembra roles) y se omite ANTES de arrancar la app si no hay stack.
 */
abstract class E2ETestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('E2E_BASE_URL')) {
            $this->markTestSkipped('E2E_BASE_URL no definida: sin stack contra el que probar (ver docs/testing.md).');
        }

        parent::setUp();
    }

    /** El stack tiene sus propios datos base: la suite no siembra nada en la BD local. */
    protected function seedRolesAndPermissions(): void {}
}

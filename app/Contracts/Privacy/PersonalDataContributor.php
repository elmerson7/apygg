<?php

namespace App\Contracts\Privacy;

use App\Models\User;

/**
 * Un módulo aporta aquí su parte de los datos personales de un usuario (docs/privacy.md). Se registra con
 * `$this->app->tag([MiContributor::class], 'privacy.contributors')` en un provider.
 *
 * Reglas: `export()` devuelve SOLO datos de ese usuario, nunca secretos (contraseñas, tokens, hashes de claves,
 * secretos 2FA); `erase()` es idempotente, corre dentro de una transacción y anonimiza o borra lo suyo.
 */
interface PersonalDataContributor
{
    /** Nombre del archivo en el ZIP (`<key>.json`): `snake_case`, único. */
    public function key(): string;

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array;

    public function erase(User $user): void;
}

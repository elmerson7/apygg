<?php

namespace App\Contracts;

use App\Support\Sequences\SequenceExhausted;

interface SequenceInterface
{
    /**
     * Reserva el siguiente número y lo devuelve ya formateado: `string` si la secuencia tiene
     * prefijo o relleno ("F-2026-000042"), `int` si no.
     *
     * Atómico (bloqueo de fila): dos peticiones nunca reciben el mismo valor. Si se llama dentro de
     * una transacción, el número queda reservado hasta que ella termine; si se revierte, el número
     * vuelve a estar libre (sin huecos).
     *
     * `$tenantId` null = tenant del contexto (multiempresa activo) o secuencia global (apagado).
     *
     * @param  array<string, mixed>  $definition  solo al crear: sobrescribe `config('sequences')`
     *
     * @throws SequenceExhausted
     */
    public function next(string $name, ?int $tenantId = null, array $definition = []): string|int;

    /**
     * Igual que `next()` pero devuelve siempre el valor numérico sin formato.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws SequenceExhausted
     */
    public function nextValue(string $name, ?int $tenantId = null, array $definition = []): int;

    /**
     * Lo que devolvería `next()` ahora, sin reservarlo ni crear la secuencia. Orientativo: otro
     * proceso puede tomarlo antes. Nunca lo uses para asignar números.
     *
     * @param  array<string, mixed>  $definition
     */
    public function peek(string $name, ?int $tenantId = null, array $definition = []): string|int;
}

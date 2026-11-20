<?php

namespace App\Contracts;

use App\Support\Idempotency\Reservation;

interface IdempotencyInterface
{
    /**
     * Reserva atómica de (tenant, actor, clave). `ACQUIRED`: esta petición ejecuta; `REPLAY`: ya hay respuesta
     * guardada; `MISMATCH`: la clave se usó con otra petición; `IN_PROGRESS`: otra la está ejecutando.
     */
    public function begin(?int $tenantId, string $actor, string $key, string $method, string $path, string $requestHash): Reservation;

    /**
     * Guarda la respuesta (cuerpo cifrado). Devuelve false si la reserva ya no era de esta petición.
     *
     * @param  array<string, list<string|null>>  $headers
     */
    public function complete(Reservation $reservation, int $status, array $headers, string $body): bool;

    /** Libera la reserva (la clave vuelve a estar libre para reintentar). */
    public function release(Reservation $reservation): void;

    /** Borra las claves caducadas (reservas muertas y respuestas viejas). Devuelve cuántas. */
    public function purgeExpired(int $batch = 1000): int;
}

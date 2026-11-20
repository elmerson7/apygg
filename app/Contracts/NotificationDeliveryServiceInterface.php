<?php

namespace App\Contracts;

use App\Models\NotificationDelivery;
use Throwable;

/**
 * NotificationDeliveryServiceInterface
 *
 * Registro de entregas con RECLAMO IDEMPOTENTE: una entrega (notificación + usuario + canal) solo la
 * envía quien consigue reclamarla; un job repetido o duplicado recibe `null` y no envía.
 */
interface NotificationDeliveryServiceInterface
{
    /**
     * Reclama la entrega de forma atómica (estado `sending`, `attempts + 1`). `null` si ya está enviada,
     * omitida, o la tiene otro en curso (con arrendamiento vigente).
     */
    public function claim(string $notificationId, int $userId, string $type, string $channel): ?NotificationDelivery;

    public function markSent(NotificationDelivery $delivery): void;

    public function markFailed(NotificationDelivery $delivery, Throwable|string $error): void;

    /** Deja constancia de una entrega que no se intentó (opt-out, silencio, sin destino...). */
    public function markSkipped(string $notificationId, int $userId, string $type, string $channel, string $reason): void;

    /** Marca como fallidas las entregas `sending` abandonadas hace más de `$minutes` minutos. */
    public function sweepStuck(int $minutes): int;

    /** Borra entregas terminadas (no `pending`/`sending`) con más de `$days` días. */
    public function purge(int $days): int;
}
